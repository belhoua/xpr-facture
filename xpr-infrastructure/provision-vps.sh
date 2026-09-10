#!/usr/bin/env bash
#
# Provisioning initial du VPS BCAT — Ubuntu 24.04, KVM 1 (1 vCPU, 4 Go RAM).
#
# À exécuter UNE FOIS, en root, sur un VPS fraîchement créé :
#
#   DOMAIN=votre-domaine.ma \
#   ADMIN_EMAIL=vous@votre-domaine.ma \
#   REPO_URL=git@github.com:votre-org/bcat.git \
#   ./provision-vps.sh
#
# Ré-exécutable sans casse (chaque étape vérifie l'existant avant d'agir),
# SAUF la partie certbot : si le DNS ne pointe pas encore vers ce serveur au
# moment du premier passage, l'obtention du certificat échoue proprement (le
# script continue) — relancer `certbot certonly --nginx -d "$DOMAIN"` à la
# main une fois le DNS propagé, puis ce script une seconde fois pour activer
# le vhost HTTPS définitif.
#
# Ce que ce script NE fait PAS, volontairement :
#   - il ne touche pas à sshd_config (désactiver le mot de passe / root SSH
#     est une action qui peut vous verrouiller dehors — à faire à la main,
#     rappel affiché en fin de script) ;
#   - il ne lance pas deploy.sh : /srv/bcat/repo est cloné, mais aucun
#     conteneur ni process PM2 n'est démarré ici (xpr-infrastructure/.env.prod
#     doit être complété d'abord — MAIL_*, XPR_ADMIN_PASSWORD, HORIZON_ADMIN_EMAILS).

set -euo pipefail

: "${DOMAIN:?Usage: DOMAIN=... ADMIN_EMAIL=... REPO_URL=... $0}"
: "${ADMIN_EMAIL:?Usage: DOMAIN=... ADMIN_EMAIL=... REPO_URL=... $0}"
: "${REPO_URL:?Usage: DOMAIN=... ADMIN_EMAIL=... REPO_URL=... $0}"
BRANCH="${BRANCH:-main}"
DEPLOY_USER="${DEPLOY_USER:-bcat}"
NODE_MAJOR="${NODE_MAJOR:-22}"

if [ "$(id -u)" -ne 0 ]; then
    echo "ERREUR : à exécuter en root (ou via sudo)." >&2
    exit 1
fi

log() { printf '\n==> %s\n' "$1"; }
warn() { printf '\n!! %s\n' "$1" >&2; }

# --- 1. Système de base -------------------------------------------------

log "mise à jour du système"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get upgrade -y -qq

log "paquets de base"
apt-get install -y -qq \
    curl ca-certificates gnupg git openssh-client ufw fail2ban unattended-upgrades \
    apt-transport-https software-properties-common

log "mises à jour de sécurité automatiques"
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null

# --- 2. Swap — coussin anti-OOM sur une boîte à 4 Go --------------------

if ! swapon --show | grep -q '/swapfile'; then
    log "création d'un swap de 2 Go"
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
    # vm.swappiness bas : le swap sert de filet de sécurité pour les pics
    # (Chromium/Gotenberg), pas de mémoire de travail permanente — un
    # swappiness par défaut (60) swapperait trop tôt et ralentirait tout.
    sysctl -w vm.swappiness=10
    grep -q '^vm.swappiness' /etc/sysctl.conf || echo 'vm.swappiness=10' >> /etc/sysctl.conf
else
    log "swap déjà présent, on saute"
fi

# --- 3. Docker Engine + Compose plugin (dépôt officiel) -----------------

if ! command -v docker &>/dev/null; then
    log "installation de Docker"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo \
        "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu \
        $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -qq
    apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
else
    log "Docker déjà installé ($(docker --version)), on saute"
fi

log "vérification de docker compose v2 (deploy.resources.limits en dépend hors Swarm)"
docker compose version

if [ ! -f /etc/docker/daemon.json ]; then
    log "rotation des logs Docker (évite de remplir le disque)"
    cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" }
}
EOF
    systemctl restart docker
else
    warn "/etc/docker/daemon.json existe déjà — non modifié, vérifiez log-opts vous-même."
fi

# --- 4. Utilisateur de déploiement (jamais root pour docker compose/PM2) --

if ! id -u "$DEPLOY_USER" &>/dev/null; then
    log "création de l'utilisateur $DEPLOY_USER"
    useradd -m -s /bin/bash "$DEPLOY_USER"
else
    log "utilisateur $DEPLOY_USER déjà présent, on saute"
fi
usermod -aG docker "$DEPLOY_USER"

# --- 5. Node.js (runtime du serveur Next standalone) + PM2 ---------------

if ! command -v node &>/dev/null; then
    log "installation de Node.js ${NODE_MAJOR}.x (NodeSource)"
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash -
    apt-get install -y -qq nodejs
else
    log "Node.js déjà installé ($(node --version)), on saute"
fi

if ! command -v pm2 &>/dev/null; then
    log "installation de PM2"
    npm install -g pm2
else
    log "PM2 déjà installé, on saute"
fi

# --- 6. Nginx + certbot ---------------------------------------------------

if ! command -v nginx &>/dev/null; then
    log "installation de Nginx"
    apt-get install -y -qq nginx
fi
if ! command -v certbot &>/dev/null; then
    log "installation de certbot"
    apt-get install -y -qq certbot python3-certbot-nginx
fi

rm -f /etc/nginx/sites-enabled/default

# Vhost minimal (port 80 seul) le temps d'obtenir le premier certificat :
# le vhost final (xpr-infrastructure/nginx/bcat.conf, avec son bloc 443) sera
# activé plus bas UNIQUEMENT une fois le certificat présent sur le disque —
# sinon `nginx -t` échoue (ssl_certificate pointant vers un fichier absent).
log "vhost Nginx temporaire (port 80, défi ACME) pour ${DOMAIN}"
cat > /etc/nginx/sites-available/bcat.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 200 "BCAT — en cours de provisioning.\n";
        add_header Content-Type text/plain;
    }
}
EOF
mkdir -p /var/www/certbot
ln -sf /etc/nginx/sites-available/bcat.conf /etc/nginx/sites-enabled/bcat.conf
nginx -t && systemctl reload nginx || systemctl restart nginx

# --- 7. Pare-feu — SSH d'abord, sous peine de se verrouiller dehors ------

log "pare-feu (UFW)"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

log "fail2ban (jail sshd par défaut)"
systemctl enable --now fail2ban

# --- 8. Arborescence /srv/bcat + clone du dépôt --------------------------

log "arborescence /srv/bcat"
mkdir -p /srv/bcat/repo /srv/bcat/frontend
chown -R "${DEPLOY_USER}:${DEPLOY_USER}" /srv/bcat

# Clé de déploiement SSH pour ${DEPLOY_USER} — nécessaire pour cloner un
# dépôt privé (REPO_URL en git@...). Idempotent : générée une seule fois,
# réutilisée aux exécutions suivantes.
DEPLOY_KEY="/home/${DEPLOY_USER}/.ssh/id_ed25519"
if [[ "$REPO_URL" == git@* ]] && [ ! -f "$DEPLOY_KEY" ]; then
    log "génération d'une clé de déploiement SSH pour ${DEPLOY_USER}"
    su - "$DEPLOY_USER" -c "mkdir -p ~/.ssh && chmod 700 ~/.ssh && \
        ssh-keygen -t ed25519 -N '' -f ~/.ssh/id_ed25519 -C '${DEPLOY_USER}@$(hostname)' -q"
    su - "$DEPLOY_USER" -c "ssh-keyscan -H github.com gitlab.com >> ~/.ssh/known_hosts 2>/dev/null" || true
fi
if [[ "$REPO_URL" == git@* ]] && [ ! -d /srv/bcat/repo/.git ]; then
    cat <<EOF

------------------------------------------------------------------
Clé publique de déploiement (${DEPLOY_USER}) — à ajouter comme Deploy Key
EN LECTURE SEULE sur le dépôt distant AVANT de continuer, si ce n'est pas
déjà fait :

$(cat "${DEPLOY_KEY}.pub")

------------------------------------------------------------------
EOF
fi

if [ ! -d /srv/bcat/repo/.git ]; then
    log "clone de ${REPO_URL} (${BRANCH})"
    if ! su - "$DEPLOY_USER" -c "git clone --branch '${BRANCH}' '${REPO_URL}' /srv/bcat/repo"; then
        warn "clone impossible. Si REPO_URL est en SSH (git@...), ajoutez la clé"
        warn "publique affichée ci-dessus comme Deploy Key GitHub/GitLab, puis"
        warn "relancez EXACTEMENT la même commande — tout ce qui précède est déjà"
        warn "en place et sera sauté (idempotent)."
        exit 1
    fi
else
    # Un clone précédent (potentiellement raté plus loin, ou juste antérieur à
    # un push) peut exister sans être à jour — sauter silencieusement laisserait
    # provisionner avec du code obsolète. Même logique que deploy.sh : fetch +
    # reset --hard, jamais de merge sur un checkout qui n'est pas un espace de
    # travail.
    log "dépôt déjà présent dans /srv/bcat/repo — mise à jour vers ${BRANCH}"
    su - "$DEPLOY_USER" -c "cd /srv/bcat/repo && git fetch --quiet origin '${BRANCH}' && git reset --hard 'origin/${BRANCH}'"
fi

# --- 9. .env.prod — généré avec des secrets forts, jamais commité --------

ENV_PROD="/srv/bcat/repo/xpr-infrastructure/.env.prod"
if [ ! -f "$ENV_PROD" ]; then
    log "génération de ${ENV_PROD} (APP_KEY et mots de passe DB aléatoires)"
    cp "/srv/bcat/repo/xpr-infrastructure/.env.prod.example" "$ENV_PROD"
    APP_KEY="base64:$(openssl rand -base64 32)"
    # Un seul mot de passe : DB_USERNAME vaut encore DB_OWNER_USERNAME
    # (xpr_owner) dans .env.prod.example — le rôle xpr_app séparé n'est pas
    # câblé côté Laravel (reliquat P0-09, cf. CLAUDE.md §15). Générer deux
    # secrets distincts pour DB_OWNER_PASSWORD et DB_PASSWORD casserait la
    # connexion : Laravel s'authentifierait en xpr_owner avec le mot de passe
    # d'un autre rôle. À séparer réellement le jour où DB_USERNAME bascule
    # sur xpr_app.
    DB_OWNER_PW="$(openssl rand -hex 24)"
    sed -i "s#^APP_KEY=.*#APP_KEY=${APP_KEY}#" "$ENV_PROD"
    sed -i "s#^DB_OWNER_PASSWORD=.*#DB_OWNER_PASSWORD=${DB_OWNER_PW}#" "$ENV_PROD"
    sed -i "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_OWNER_PW}#" "$ENV_PROD"
    sed -i "s#votre-domaine\.ma#${DOMAIN}#g" "$ENV_PROD"
    chown "${DEPLOY_USER}:${DEPLOY_USER}" "$ENV_PROD"
    chmod 600 "$ENV_PROD"
else
    log "${ENV_PROD} existe déjà, non modifié"
fi

# --- 10. Certificat TLS ---------------------------------------------------

if [ ! -f "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]; then
    log "tentative d'obtention du certificat (échoue si le DNS n'est pas encore propagé — non bloquant)"
    if certbot certonly --nginx --non-interactive --agree-tos \
            -m "$ADMIN_EMAIL" -d "$DOMAIN"; then
        log "certificat obtenu — activation du vhost HTTPS définitif"
        sed "s#votre-domaine\.ma#${DOMAIN}#g" \
            /srv/bcat/repo/xpr-infrastructure/nginx/bcat.conf \
            > /etc/nginx/sites-available/bcat.conf
        nginx -t && systemctl reload nginx
    else
        warn "certificat NON obtenu. Une fois le DNS de ${DOMAIN} propagé vers ce serveur, relancez :"
        warn "  certbot certonly --nginx --agree-tos -m ${ADMIN_EMAIL} -d ${DOMAIN}"
        warn "  puis ce script une seconde fois pour activer le vhost HTTPS définitif."
    fi
else
    log "certificat déjà présent — activation du vhost HTTPS définitif"
    sed "s#votre-domaine\.ma#${DOMAIN}#g" \
        /srv/bcat/repo/xpr-infrastructure/nginx/bcat.conf \
        > /etc/nginx/sites-available/bcat.conf
    nginx -t && systemctl reload nginx
fi

# --- 11. PM2 : démarrage au boot pour l'utilisateur de déploiement -------

log "service systemd PM2 pour ${DEPLOY_USER}"
su - "$DEPLOY_USER" -c "pm2 install pm2-logrotate" >/dev/null
STARTUP_CMD="$(su - "$DEPLOY_USER" -c "pm2 startup systemd -u ${DEPLOY_USER} --hp /home/${DEPLOY_USER}" 2>/dev/null | grep -E '^(sudo )?env ' || true)"
if [ -n "$STARTUP_CMD" ]; then
    eval "$STARTUP_CMD"
fi
su - "$DEPLOY_USER" -c "pm2 save" >/dev/null

# --- Récapitulatif ---------------------------------------------------------

cat <<EOF

==================================================================
Provisioning terminé. Reste à faire, à la main, avant le premier
déploiement (./deploy.sh) :

  1. Compléter ${ENV_PROD} :
       MAIL_HOST / MAIL_USERNAME / MAIL_PASSWORD
       XPR_ADMIN_PASSWORD (obligatoire)
       HORIZON_ADMIN_EMAILS (sinon /horizon reste inaccessible)

  2. Si le certificat n'a pas pu être obtenu ci-dessus : pointer le
     DNS de ${DOMAIN} vers l'IP de ce serveur, puis :
       certbot certonly --nginx --agree-tos -m ${ADMIN_EMAIL} -d ${DOMAIN}
       nginx -t && systemctl reload nginx

  3. Construire et transférer le premier build frontend vers
     /srv/bcat/frontend (cf. xpr-frontend/ecosystem.config.js,
     section « méthode de transfert du build » — depuis votre poste
     ou la CI, jamais sur ce serveur).

  4. Lancer /srv/bcat/repo/deploy.sh (en tant que ${DEPLOY_USER}).

  5. Recommandé, non automatisé ici (irréversible sans accès console) :
     désactiver l'authentification SSH par mot de passe et le login
     root dans /etc/ssh/sshd_config, une fois l'accès par clé vérifié.
==================================================================
EOF
