# Image de PRODUCTION du backend (Render, un seul conteneur).
#
# Pourquoi FrankenPHP et non le couple nginx + php-fpm de
# xpr-infrastructure/docker/php/Dockerfile : Render ne lance qu'UN process par
# service, et le plan gratuit plafonne à 512 Mo de RAM. FrankenPHP embarque le
# serveur HTTP dans le binaire PHP — un seul process, pas de supervisor.
#
# Mode CLASSIQUE, pas le mode worker : ce dernier garde l'application en mémoire
# entre les requêtes, ce qui invaliderait la portée « session » de set_config()
# utilisée par TenantContext pour armer la RLS (cf. CLAUDE.md §15). Chaque
# requête doit repartir d'un état PHP neuf, exactement comme sous php-fpm.
#
# Le développement local ne passe PAS par ce fichier : `make up` continue
# d'utiliser l'image php-fpm de xpr-infrastructure.
FROM dunglas/frankenphp:1-php8.3-alpine

# Mêmes extensions que l'image de dev, pour la parité :
#   pdo_pgsql → PostgreSQL   intl → localisation FR/AR   bcmath → montants
#   zip, gd   → fichiers     pcntl → signaux             opcache → performance
# redis est volontairement absent : la production gratuite n'a pas de Redis,
# sessions/cache/queue passent par PostgreSQL (cf. .env.example).
RUN install-php-extensions pdo_pgsql intl bcmath zip gd pcntl opcache

# FrankenPHP sert /app/public par défaut : c'est la racine web de Laravel.
WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dépendances d'abord, code ensuite : tant que composer.lock ne bouge pas,
# Docker réutilise cette couche et le déploiement ne réinstalle rien.
# --no-scripts : les scripts Laravel appellent artisan, qui a besoin du code
# applicatif — pas encore copié à ce stade.
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress

COPY . .

# --classmap-authoritative : plus aucun accès disque pour résoudre une classe.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chmod +x docker/entrypoint.sh

# Le serveur tourne en root dans l'image FrankenPHP ; storage/ et bootstrap/cache
# doivent rester inscriptibles (logs, cache de config, sessions de secours).
RUN chmod -R ug+rw storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false

# La sonde Render tape /up (déclarée par withRouting(health:) côté Laravel).
EXPOSE 8080

ENTRYPOINT ["/app/docker/entrypoint.sh"]
