#!/bin/sh
# Démarrage du backend en production (Render).
#
# Tout se joue ici et non au build : sur Render les variables d'environnement
# ne sont injectées qu'à l'exécution. Mettre en cache la configuration pendant
# le build figerait des valeurs vides.
set -e

echo "→ XPR Facture — démarrage du backend"

# --- Garde-fous : échouer avec un message lisible plutôt qu'une stack trace ---

if [ -z "${APP_KEY}" ]; then
    echo "ERREUR : APP_KEY est vide."
    echo "  Générez-la en local avec :  php artisan key:generate --show"
    echo "  puis collez la valeur (base64:...) dans les variables Render."
    exit 1
fi

if [ -z "${DB_HOST}" ]; then
    echo "ERREUR : DB_HOST est vide — la connexion PostgreSQL n'est pas configurée."
    echo "  Renseignez DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD (Neon)."
    exit 1
fi

# Render impose le port d'écoute via $PORT. SERVER_NAME sans nom d'hôte fait
# écouter Caddy en HTTP simple : c'est Render qui termine le TLS en amont.
export SERVER_NAME=":${PORT:-8080}"
echo "→ écoute sur ${SERVER_NAME}"

# --- Schéma ---

# Idempotent : Laravel ne rejoue que les migrations absentes de la table
# `migrations`. La première requête réveille aussi la base Neon endormie.
echo "→ migrations"
php artisan migrate --force

# Référentiels PRÉREQUIS du schéma, pas des données de démo :
#   - devises   : FK companies.default_currency
#   - rôles     : requis par le provisioning d'une société (Spatie)
#   - taux TVA  : catalogue standard partagé (company_id NULL)
# Les trois seeders sont idempotents (vérifié) : rejouables à chaque démarrage.
# DemoSeeder est délibérément exclu — aucune donnée factice en production.
echo "→ référentiels (devises, rôles, TVA)"
php artisan db:seed --force --class="Database\\Seeders\\CurrencySeeder"
php artisan db:seed --force --class="Database\\Seeders\\RoleSeeder"
php artisan db:seed --force --class="Database\\Seeders\\TaxRateSeeder"

# --- Caches applicatifs ---

# route:cache exige que toute route pointe sur un contrôleur : aucune closure
# d'action ne subsiste (routes/web.php est vide, les modules n'exposent que des
# contrôleurs). event:cache résout les listeners sans scanner le disque.
echo "→ caches (config, routes, événements)"
php artisan config:cache
php artisan route:cache
php artisan event:cache

echo "→ prêt"

# exec : FrankenPHP devient PID 1 et reçoit les signaux d'arrêt de Render,
# ce qui permet un arrêt propre plutôt qu'un SIGKILL.
exec frankenphp run --config /etc/frankenphp/Caddyfile
