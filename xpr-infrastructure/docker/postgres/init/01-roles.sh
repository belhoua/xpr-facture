#!/bin/sh
# Crée le rôle applicatif xpr_app : LOGIN, NOBYPASSRLS, non propriétaire des
# tables. La RLS ne s'applique jamais à un rôle BYPASSRLS ou superuser ; c'est
# donc ce rôle, et lui seul, que l'application utilise à l'exécution.
# Les migrations Laravel tournent avec le rôle owner (POSTGRES_USER).
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE ROLE ${XPR_APP_USER} LOGIN PASSWORD '${XPR_APP_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
    GRANT USAGE ON SCHEMA public TO ${XPR_APP_USER};
    -- Les tables créées ensuite par les migrations (rôle owner) seront accessibles
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO ${XPR_APP_USER};
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT USAGE, SELECT ON SEQUENCES TO ${XPR_APP_USER};
    ALTER DEFAULT PRIVILEGES FOR ROLE ${POSTGRES_USER} IN SCHEMA public
        GRANT EXECUTE ON FUNCTIONS TO ${XPR_APP_USER};
EOSQL
