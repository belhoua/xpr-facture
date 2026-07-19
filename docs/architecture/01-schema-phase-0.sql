-- ============================================================================
-- XPR Suite — Schéma Phase 0 (socle)
-- PostgreSQL 16 — livrable de conception, sera transposé en migrations Laravel.
--
-- Conventions transverses :
--   * PK uuid v7 (fonction ci-dessous ; côté app : HasVersion7Uuids).
--   * Montants : BIGINT en centimes + colonne currency CHAR(3). Jamais de float.
--   * Toutes les tables tenant portent company_id NOT NULL + index composite.
--   * created_at/updated_at TIMESTAMPTZ (UTC), deleted_at pour le soft delete
--     uniquement là où la suppression est légalement admissible.
--   * Les tables des packages (Spatie Permission en mode teams=company_id,
--     Sanctum personal_access_tokens, jobs/failed_jobs) ne sont pas re-déclarées
--     ici : leurs migrations font foi, configuration notée en fin de fichier.
-- ============================================================================

CREATE EXTENSION IF NOT EXISTS pgcrypto;    -- gen_random_uuid()
CREATE EXTENSION IF NOT EXISTS unaccent;    -- recherche latin sans accents
CREATE EXTENSION IF NOT EXISTS btree_gist;  -- contrainte d'exclusion (exercices)
CREATE EXTENSION IF NOT EXISTS citext;      -- e-mails insensibles à la casse

-- ----------------------------------------------------------------------------
-- UUID v7 : non natif en PG16 (natif en PG18). Timestamp ms + bits aléatoires,
-- donc PK ordonnées dans le temps (index B-tree denses, pas de fuite métier).
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION uuid_generate_v7() RETURNS uuid
LANGUAGE sql VOLATILE PARALLEL SAFE AS $$
  SELECT encode(
    set_bit(
      set_bit(
        overlay(uuid_send(gen_random_uuid())
                PLACING substring(int8send((extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
                FROM 1 FOR 6),
        52, 1),
      53, 1),
    'hex')::uuid;
$$;

-- ============================================================================
-- IDENTITÉ & TENANCY
-- ============================================================================

-- Utilisateurs : table GLOBALE (un utilisateur peut appartenir à N sociétés).
-- Pas de company_id ici ; l'appartenance vit dans company_user.
CREATE TABLE users (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    name                varchar(150) NOT NULL,
    email               citext NOT NULL,
    email_verified_at   timestamptz,
    password            varchar(255) NOT NULL,          -- hash argon2id
    locale              varchar(5)  NOT NULL DEFAULT 'fr'
                        CONSTRAINT users_locale_check CHECK (locale IN ('fr', 'ar', 'en')),
    -- société affichée par défaut à la connexion (nullable : résolue au 1er login)
    default_company_id  uuid,                           -- FK ajoutée après CREATE companies
    remember_token      varchar(100),
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz
);
-- Unicité e-mail parmi les comptes actifs (un e-mail supprimé peut se réinscrire)
CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL;

-- Sociétés (tenants). Identité légale marocaine complète.
-- Pas d'unicité sur l'ICE : cf. critique §4 Q3 (cabinets comptables).
CREATE TABLE companies (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    legal_name          varchar(255) NOT NULL,          -- raison sociale
    trade_name          varchar(255),                   -- nom commercial
    legal_form          varchar(20) NOT NULL
                        CONSTRAINT companies_legal_form_check CHECK (legal_form IN
                          ('auto_entrepreneur','sarl','sarl_au','sa','sas','snc','cooperative')),
    ice                 char(15)
                        CONSTRAINT companies_ice_format_check CHECK (ice ~ '^[0-9]{15}$'),
    if_number           varchar(20),                    -- identifiant fiscal
    rc_number           varchar(20),                    -- registre de commerce
    rc_city             varchar(100),                   -- ville du tribunal
    patente             varchar(20),                    -- taxe professionnelle
    cnss                varchar(20),                    -- n° affiliation si employeur
    share_capital       bigint,                         -- capital en centimes, NULL pour AE
    -- Régime TVA : conditionne la déclaration (Phase 2), stocké dès maintenant.
    vat_regime          varchar(15) NOT NULL DEFAULT 'debit'
                        CONSTRAINT companies_vat_regime_check
                        CHECK (vat_regime IN ('debit','encaissement')),
    -- AE sous seuil : déclenche la mention "TVA non applicable" sur les documents
    vat_exempt          boolean NOT NULL DEFAULT false,
    address             text,
    city                varchar(100),
    country             char(2) NOT NULL DEFAULT 'MA',
    phone               varchar(30),
    email               citext,
    website             varchar(255),
    logo_file_id        uuid,                           -- FK ajoutée après CREATE files
    default_currency    char(3) NOT NULL DEFAULT 'MAD', -- FK ajoutée après CREATE currencies
    timezone            varchar(64) NOT NULL DEFAULT 'Africa/Casablanca',
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz
);

ALTER TABLE users
  ADD CONSTRAINT users_default_company_fk
  FOREIGN KEY (default_company_id) REFERENCES companies (id) ON DELETE SET NULL;

-- Appartenance utilisateur ↔ société. Le rôle effectif est géré par Spatie
-- Permission en mode teams (team_id = company_id) ; ce pivot porte le lien
-- d'appartenance et les métadonnées d'invitation.
CREATE TABLE company_user (
    id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id    uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    user_id       uuid NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    invited_by    uuid REFERENCES users (id) ON DELETE SET NULL,
    invited_at    timestamptz,
    joined_at     timestamptz,                          -- NULL = invitation en attente
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT company_user_unique UNIQUE (company_id, user_id)
);
CREATE INDEX company_user_user_idx ON company_user (user_id);

-- ============================================================================
-- RÉFÉRENTIELS FISCAUX & MONÉTAIRES
-- ============================================================================

-- Devises : référentiel global (ISO 4217), pas de company_id.
CREATE TABLE currencies (
    code            char(3) PRIMARY KEY,                -- 'MAD', 'EUR', 'USD'
    name_fr         varchar(80) NOT NULL,
    name_ar         varchar(80) NOT NULL,
    symbol          varchar(8)  NOT NULL,
    decimal_places  smallint    NOT NULL DEFAULT 2,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);

-- Taux de change historisés, par société (chacune saisit/importe les siens).
CREATE TABLE exchange_rates (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    currency_code   char(3) NOT NULL REFERENCES currencies (code),
    -- 1 unité de currency_code = rate MAD (devise pivot de la société)
    rate            numeric(18,8) NOT NULL CONSTRAINT exchange_rates_positive CHECK (rate > 0),
    effective_date  date NOT NULL,
    source          varchar(50),                        -- 'manual', 'bkam', ...
    created_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT exchange_rates_unique UNIQUE (company_id, currency_code, effective_date)
);

-- Taux de TVA paramétrables par société (seedés à la création : 0/7/10/14/20,
-- exonéré, hors champ). Jamais codés en dur — la réglementation bouge.
CREATE TABLE tax_rates (
    id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id    uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    label_fr      varchar(80) NOT NULL,                 -- 'TVA 20 %'
    label_ar      varchar(80) NOT NULL,
    -- Pourcentage (pas un montant) : numeric exact, ex. 20.00
    rate          numeric(5,2) NOT NULL CONSTRAINT tax_rates_range CHECK (rate >= 0 AND rate <= 100),
    kind          varchar(12) NOT NULL DEFAULT 'standard'
                  CONSTRAINT tax_rates_kind_check CHECK (kind IN ('standard','exonere','hors_champ')),
    is_default    boolean NOT NULL DEFAULT false,
    is_active     boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now(),
    deleted_at    timestamptz
);
CREATE INDEX tax_rates_company_idx ON tax_rates (company_id, is_active);

-- Exercices comptables. La contrainte d'exclusion interdit tout chevauchement
-- d'exercices au sein d'une même société (btree_gist requis).
CREATE TABLE fiscal_years (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    label       varchar(20) NOT NULL,                   -- '2026'
    starts_on   date NOT NULL,
    ends_on     date NOT NULL,
    status      varchar(10) NOT NULL DEFAULT 'open'
                CONSTRAINT fiscal_years_status_check CHECK (status IN ('open','closing','closed')),
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT fiscal_years_dates_check CHECK (ends_on > starts_on),
    CONSTRAINT fiscal_years_label_unique UNIQUE (company_id, label),
    CONSTRAINT fiscal_years_no_overlap
      EXCLUDE USING gist (company_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&)
);

-- Séquences de numérotation : une ligne par société × type × exercice.
-- Attribution : SELECT ... FOR UPDATE sur la ligne, incrément, formatage —
-- DANS LA MÊME TRANSACTION que le passage à 'validated' (cf. critique §1.3),
-- sinon un rollback crée un trou.
CREATE TABLE sequences (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    fiscal_year_id  uuid NOT NULL REFERENCES fiscal_years (id) ON DELETE RESTRICT,
    document_type   varchar(20) NOT NULL
                    CONSTRAINT sequences_doc_type_check CHECK (document_type IN
                      ('invoice','quote','credit_note','delivery_note','purchase_order')),
    format          varchar(50) NOT NULL,               -- 'FAC-{YYYY}-{0000}'
    next_number     integer NOT NULL DEFAULT 1
                    CONSTRAINT sequences_positive CHECK (next_number >= 1),
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT sequences_unique UNIQUE (company_id, document_type, fiscal_year_id)
);

-- ============================================================================
-- SOCLE TRANSVERSE : settings, audit, fichiers, idempotence
-- ============================================================================

-- Paramètres clé/valeur. company_id NULL = valeur par défaut plateforme,
-- surchargée par la ligne de la société si elle existe.
CREATE TABLE settings (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid REFERENCES companies (id) ON DELETE CASCADE,
    key         varchar(100) NOT NULL,
    value       jsonb NOT NULL,
    updated_by  uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
-- Unicité de la clé par périmètre (NULLS NOT DISTINCT : une seule ligne globale par clé)
CREATE UNIQUE INDEX settings_scope_key_unique ON settings (company_id, key) NULLS NOT DISTINCT;

-- Journal d'audit : append-only (ni UPDATE ni DELETE applicatifs).
-- company_id nullable : les événements d'auth (login, échec) ne sont pas tenant.
-- Volumineux à terme → candidat au partitionnement par mois (décision Phase 2+).
CREATE TABLE audit_logs (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid REFERENCES companies (id) ON DELETE SET NULL,
    user_id         uuid REFERENCES users (id) ON DELETE SET NULL,
    action          varchar(50) NOT NULL,               -- 'invoice.validated', 'auth.login', ...
    auditable_type  varchar(120),                       -- FQCN du modèle
    auditable_id    uuid,
    old_values      jsonb,
    new_values      jsonb,
    ip_address      inet,
    user_agent      varchar(500),
    request_id      uuid,                               -- corrélation avec les logs applicatifs
    created_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX audit_logs_subject_idx  ON audit_logs (company_id, auditable_type, auditable_id);
CREATE INDEX audit_logs_company_date ON audit_logs (company_id, created_at);

-- Fichiers (logos, pièces jointes, PDF archivés). Stockage MinIO/S3 hors
-- webroot, nom de fichier aléatoire = path ; l'original n'est qu'un libellé.
CREATE TABLE files (
    id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id       uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    uploaded_by      uuid REFERENCES users (id) ON DELETE SET NULL,
    disk             varchar(30) NOT NULL DEFAULT 's3',
    path             varchar(500) NOT NULL,             -- clé objet aléatoire
    original_name    varchar(255) NOT NULL,
    mime_type        varchar(120) NOT NULL,             -- MIME réel détecté, pas déclaré
    size_bytes       bigint NOT NULL CONSTRAINT files_size_check CHECK (size_bytes >= 0),
    checksum_sha256  char(64) NOT NULL,
    -- rattachement polymorphe optionnel (facture → PDF archivé, etc.)
    attachable_type  varchar(120),
    attachable_id    uuid,
    created_at       timestamptz NOT NULL DEFAULT now(),
    deleted_at       timestamptz
);
CREATE INDEX files_attachable_idx ON files (company_id, attachable_type, attachable_id);

ALTER TABLE companies
  ADD CONSTRAINT companies_logo_fk
  FOREIGN KEY (logo_file_id) REFERENCES files (id) ON DELETE SET NULL;
ALTER TABLE companies
  ADD CONSTRAINT companies_currency_fk
  FOREIGN KEY (default_currency) REFERENCES currencies (code);

-- Idempotence des créations (facture, paiement) : la première requête stocke
-- sa réponse ; les rejeux avec la même clé la restituent sans ré-exécuter.
-- Purge par job au-delà d'expires_at.
CREATE TABLE idempotency_keys (
    id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id     uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    user_id        uuid REFERENCES users (id) ON DELETE SET NULL,
    idem_key       varchar(100) NOT NULL,               -- header Idempotency-Key
    endpoint       varchar(150) NOT NULL,               -- 'POST /api/v1/invoices'
    request_hash   char(64) NOT NULL,                   -- détecte un rejeu avec corps différent → 422
    response_code  smallint,
    response_body  jsonb,
    created_at     timestamptz NOT NULL DEFAULT now(),
    expires_at     timestamptz NOT NULL,
    CONSTRAINT idempotency_unique UNIQUE (company_id, endpoint, idem_key)
);

-- ============================================================================
-- ROW LEVEL SECURITY (défense en profondeur, en complément du scope Eloquent)
--
-- Contexte : le middleware tenant exécute, en début de transaction :
--   SET LOCAL app.company_id = '<uuid>';
-- Les jobs Horizon restaurent ce contexte depuis leur payload.
-- L'app se connecte avec le rôle xpr_app (non-owner, sans BYPASSRLS) ;
-- les migrations utilisent le rôle owner.
-- Contexte absent → aucune ligne visible. NULLIF est indispensable : après un
-- SET LOCAL commité, la GUC retombe sur '' (pas NULL) et ''::uuid lèverait
-- une erreur sur chaque requête suivante de la connexion poolée.
-- ============================================================================

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'exchange_rates','tax_rates','fiscal_years','sequences',
    'files','idempotency_keys'
  ] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format(
      'CREATE POLICY tenant_isolation ON %I
         USING (company_id = NULLIF(current_setting(''app.company_id'', true), '''')::uuid)
         WITH CHECK (company_id = NULLIF(current_setting(''app.company_id'', true), '''')::uuid)', t);
  END LOOP;
END $$;

-- company_user : policy SPÉCIFIQUE (découverte à l'implémentation, 2026-07-19).
-- La policy standard créerait un cercle vicieux : résoudre les sociétés d'un
-- utilisateur exige de lire ce pivot AVANT toute société active. La lecture
-- est donc aussi permise par app.user_id (posé dès l'authentification) ;
-- l'écriture reste scopée à la société active.
ALTER TABLE company_user ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_user FORCE ROW LEVEL SECURITY;
CREATE POLICY membership_visibility ON company_user
  USING (
    user_id = NULLIF(current_setting('app.user_id', true), '')::uuid
    OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid
  )
  WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);

-- settings : lignes globales (company_id NULL) lisibles par tous, modifiables
-- par personne via le rôle applicatif (seed/console uniquement).
ALTER TABLE settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE settings FORCE ROW LEVEL SECURITY;
CREATE POLICY settings_read ON settings FOR SELECT
  USING (company_id IS NULL OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
CREATE POLICY settings_write ON settings FOR ALL
  USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
  WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);

-- audit_logs : INSERT tenant libre, lecture tenant, jamais d'UPDATE/DELETE.
ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;
CREATE POLICY audit_read   ON audit_logs FOR SELECT
  USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
CREATE POLICY audit_insert ON audit_logs FOR INSERT
  WITH CHECK (company_id IS NULL OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);

-- NB : users, companies, currencies ne sont pas sous RLS (tables globales) ;
-- leur accès est contrôlé applicativement (Policies + pivot company_user).

-- ============================================================================
-- TABLES DE PACKAGES (non re-déclarées ici — configuration imposée) :
--   * spatie/laravel-permission : mode "teams" activé, team_foreign_key =
--     company_id → un rôle PAR société pour chaque utilisateur.
--   * laravel/sanctum : personal_access_tokens (Phase 0 : auth SPA par cookies,
--     la table sert en Phase 4 pour l'API publique).
--   * queues : jobs, failed_jobs, job_batches (driver Redis via Horizon,
--     failed_jobs en Postgres pour l'inspection).
-- ============================================================================
