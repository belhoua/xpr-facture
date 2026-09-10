#!/usr/bin/env bash
#
# Déploiement BCAT — à exécuter SUR LE VPS (jamais en local/CI).
#
# Prérequis avant le tout premier lancement :
#   - /srv/bcat/repo      : clone git de ce monorepo (ce script y vit aussi,
#                           à la racine, comme dans le dépôt)
#   - /srv/bcat/frontend  : build `standalone` Next.js déjà déposé par rsync
#                           (cf. xpr-frontend/ecosystem.config.js — section
#                           « méthode de transfert du build »)
#   - xpr-infrastructure/.env.prod rempli (copié depuis .env.prod.example)
#   - docker compose v2 (>= 2.20), PM2 et Nginx déjà installés/configurés
#     (cf. xpr-infrastructure/nginx/bcat.conf)
#
# « Sans coupure » = migrations idempotentes et non interactives (--force),
# jamais de DROP destructeur — PAS un zero-downtime au sens strict : recréer
# le conteneur `app` interrompt les requêtes en vol quelques secondes. Un
# vrai blue-green demanderait deux conteneurs `app` derrière une bascule,
# hors de portée d'un unique vCPU pour l'instant.

set -euo pipefail

REPO_DIR="/srv/bcat/repo"
FRONTEND_DIR="/srv/bcat/frontend"
COMPOSE_FILE="${REPO_DIR}/xpr-infrastructure/docker-compose.prod.yml"
ENV_FILE="${REPO_DIR}/xpr-infrastructure/.env.prod"
BRANCH="${BCAT_DEPLOY_BRANCH:-main}"

compose() {
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
}

log() { printf '\n==> %s\n' "$1"; }

cd "$REPO_DIR"

# --- 0. Le checkout du VPS est un artefact de déploiement, pas un espace de
#        travail : une modification locale non commitée signale une
#        intervention manuelle oubliée — on s'arrête plutôt que de l'écraser.
if [ -n "$(git status --porcelain)" ]; then
    echo "ERREUR : modifications locales non commitées dans ${REPO_DIR}." >&2
    echo "  git status  pour les voir, puis commiter/stasher avant de redéployer." >&2
    exit 1
fi

log "git pull (${BRANCH})"
git fetch --quiet origin "$BRANCH"
git reset --hard "origin/${BRANCH}"

log "vérification du build frontend"
if [ ! -f "${FRONTEND_DIR}/server.js" ]; then
    echo "ERREUR : ${FRONTEND_DIR}/server.js absent." >&2
    echo "  Le build standalone n'a pas été transféré avant ce déploiement" >&2
    echo "  (cf. xpr-frontend, méthode de transfert du build)." >&2
    exit 1
fi

log "reconstruction des images backend (app, horizon)"
compose build app horizon

log "recréation des conteneurs backend"
# up -d ne recrée que ce qui a changé : postgres/redis/gotenberg ne
# redémarrent pas si leur définition est inchangée. L'entrypoint de `app`
# rejoue déjà migrate --force + caches à ce moment-là ; les étapes explicites
# qui suivent sont redondantes mais assurent une trace claire dans ce script,
# comme demandé.
compose up -d postgres redis gotenberg app horizon

log "migrations de base de données (--force, idempotentes)"
compose exec -T app php artisan migrate --force

log "mise en cache des configs/routes/événements Laravel"
compose exec -T app php artisan config:cache
compose exec -T app php artisan route:cache
compose exec -T app php artisan event:cache

log "redémarrage propre des workers Horizon"
# horizon:terminate finit le job en cours puis quitte ; restart: unless-stopped
# (docker-compose.prod.yml) relance aussitôt le conteneur sur la nouvelle
# image, qui remet sa propre config en cache (cf. command du service horizon).
compose exec -T horizon php artisan horizon:terminate

log "rechargement PM2 (frontend)"
# startOrGracefulReload : démarre le process s'il n'existe pas encore
# (premier déploiement), le recharge sans coupure sinon.
pm2 startOrGracefulReload "${FRONTEND_DIR}/ecosystem.config.js"
pm2 save

log "déploiement terminé"
compose ps
