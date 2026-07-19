-- ============================================================================
-- XPR Suite — Schéma Phase 1 (MVP facturable)
-- Dépend de 01-schema-phase-0.sql. PostgreSQL 16.
--
-- Clients → Catégories → Produits → Devis → Factures → Avoirs → Encaissements.
-- Les avoirs sont inclus ici (recommandation critique §1.1 — arbitrage attendu).
--
-- Invariants documents :
--   * number NULL tant que brouillon ; attribué par `sequences` à la validation,
--     dans la même transaction que le changement de statut.
--   * À la validation : snapshot JSONB vendeur + acheteur, taux de TVA copiés
--     en dur sur chaque ligne, PDF archivé dans `files`.
--   * Un document validé est immuable (trigger en fin de fichier + Policy app).
--   * Tous les montants en centimes de `currency` ; exchange_rate convertit
--     vers le MAD pour les rapports (1 si currency = MAD).
--   * "En retard" et "expiré" sont DÉRIVÉS (due_date / valid_until), jamais stockés.
-- ============================================================================

-- ============================================================================
-- CLIENTS
-- ============================================================================

CREATE TABLE clients (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id          uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    kind                varchar(12) NOT NULL DEFAULT 'company'
                        CONSTRAINT clients_kind_check CHECK (kind IN ('company','individual')),
    legal_name          varchar(255) NOT NULL,
    trade_name          varchar(255),
    -- Identité fiscale : obligatoire sur facture B2B, absente pour un particulier.
    -- L'obligation est vérifiée applicativement à la VALIDATION du document
    -- (kind='company' → ice requis), pas à la création de la fiche.
    ice                 char(15)
                        CONSTRAINT clients_ice_format_check CHECK (ice ~ '^[0-9]{15}$'),
    if_number           varchar(20),
    rc_number           varchar(20),
    rc_city             varchar(100),
    address             text,
    city                varchar(100),
    country             char(2) NOT NULL DEFAULT 'MA',
    email               citext,
    phone               varchar(30),
    -- Préférences de facturation
    document_language   varchar(2) NOT NULL DEFAULT 'fr'
                        CONSTRAINT clients_doc_lang_check CHECK (document_language IN ('fr','ar')),
    payment_terms_days  smallint NOT NULL DEFAULT 30
                        CONSTRAINT clients_terms_check CHECK (payment_terms_days >= 0),
    default_currency    char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    notes               text,
    is_active           boolean NOT NULL DEFAULT true,
    -- Recherche : config 'simple' (pas de config arabe native en PG) + unaccent
    -- appliqué côté app sur le latin avant indexation si besoin.
    search_vector       tsvector GENERATED ALWAYS AS (
                          to_tsvector('simple',
                            coalesce(legal_name,'') || ' ' ||
                            coalesce(trade_name,'') || ' ' ||
                            coalesce(ice,''))
                        ) STORED,
    created_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    updated_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz   -- soft delete admis : les documents validés
                                      -- gardent leur snapshot, la fiche peut disparaître
);
CREATE INDEX clients_company_name_idx  ON clients (company_id, legal_name);
CREATE INDEX clients_company_active_idx ON clients (company_id, is_active) WHERE deleted_at IS NULL;
CREATE INDEX clients_search_idx        ON clients USING gin (search_vector);

-- ============================================================================
-- CATALOGUE : catégories, produits/services
-- ============================================================================

CREATE TABLE categories (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    parent_id   uuid REFERENCES categories (id) ON DELETE SET NULL,
    name        varchar(120) NOT NULL,
    position    smallint NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz
);
CREATE UNIQUE INDEX categories_name_unique
  ON categories (company_id, name) WHERE deleted_at IS NULL;

CREATE TABLE products (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id          uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    category_id         uuid REFERENCES categories (id) ON DELETE SET NULL,
    kind                varchar(10) NOT NULL DEFAULT 'product'
                        CONSTRAINT products_kind_check CHECK (kind IN ('product','service')),
    reference           varchar(50),                    -- SKU, libre
    name                varchar(255) NOT NULL,
    description         text,
    unit                varchar(20) NOT NULL DEFAULT 'unité',  -- unité, heure, jour, kg…
    unit_price_ht       bigint NOT NULL DEFAULT 0
                        CONSTRAINT products_price_check CHECK (unit_price_ht >= 0),
    currency            char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    default_tax_rate_id uuid REFERENCES tax_rates (id) ON DELETE SET NULL,
    -- Stock léger (Phase 1) : quantité simple, pas de dépôts (Phase 4).
    track_stock         boolean NOT NULL DEFAULT false,
    stock_quantity      numeric(14,3) NOT NULL DEFAULT 0,
    stock_alert_at      numeric(14,3),
    is_active           boolean NOT NULL DEFAULT true,
    search_vector       tsvector GENERATED ALWAYS AS (
                          to_tsvector('simple',
                            coalesce(name,'') || ' ' ||
                            coalesce(reference,'') || ' ' ||
                            coalesce(description,''))
                        ) STORED,
    created_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    updated_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz
);
CREATE UNIQUE INDEX products_reference_unique
  ON products (company_id, reference) WHERE reference IS NOT NULL AND deleted_at IS NULL;
CREATE INDEX products_company_active_idx ON products (company_id, is_active) WHERE deleted_at IS NULL;
CREATE INDEX products_search_idx ON products USING gin (search_vector);

-- ============================================================================
-- ENCAISSEMENT : comptes bancaires, moyens de paiement
-- (remontés de la liste "conception" car les paiements en dépendent)
-- ============================================================================

CREATE TABLE bank_accounts (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    label       varchar(120) NOT NULL,
    bank_name   varchar(120) NOT NULL,
    rib         char(24)                                -- RIB marocain : 24 chiffres
                CONSTRAINT bank_accounts_rib_check CHECK (rib ~ '^[0-9]{24}$'),
    swift       varchar(11),
    currency    char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    is_default  boolean NOT NULL DEFAULT false,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    deleted_at  timestamptz
);
CREATE INDEX bank_accounts_company_idx ON bank_accounts (company_id, is_active);

-- Moyens de paiement : seedés par société (espèces, chèque, virement, effet,
-- TPE), extensibles. `code` stable pour la logique (timbre fiscal sur 'cash').
CREATE TABLE payment_methods (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    code        varchar(20) NOT NULL
                CONSTRAINT payment_methods_code_check CHECK (code IN
                  ('cash','check','bank_transfer','bill_of_exchange','card','other')),
    label_fr    varchar(80) NOT NULL,
    label_ar    varchar(80) NOT NULL,
    is_active   boolean NOT NULL DEFAULT true,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT payment_methods_unique UNIQUE (company_id, code)
);

-- ============================================================================
-- DEVIS
-- ============================================================================

CREATE TABLE quotes (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    client_id       uuid NOT NULL REFERENCES clients (id) ON DELETE RESTRICT,
    fiscal_year_id  uuid REFERENCES fiscal_years (id) ON DELETE RESTRICT, -- fixé à la validation
    number          varchar(30),                        -- NULL en brouillon
    status          varchar(12) NOT NULL DEFAULT 'draft'
                    CONSTRAINT quotes_status_check CHECK (status IN
                      ('draft','validated','sent','accepted','declined','invoiced','cancelled')),
    issue_date      date NOT NULL DEFAULT CURRENT_DATE,
    valid_until     date,                               -- expiration DÉRIVÉE de cette date
    currency        char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    exchange_rate   numeric(18,8) NOT NULL DEFAULT 1 CONSTRAINT quotes_rate_check CHECK (exchange_rate > 0),
    -- Totaux dénormalisés (centimes), recalculés par le service à chaque
    -- modification de ligne, figés à la validation.
    subtotal_ht     bigint NOT NULL DEFAULT 0,
    total_discount  bigint NOT NULL DEFAULT 0,
    total_vat       bigint NOT NULL DEFAULT 0,
    total_ttc       bigint NOT NULL DEFAULT 0,
    notes           text,                               -- visibles sur le PDF
    internal_notes  text,                               -- jamais sur le PDF
    -- Snapshots figés à la validation (identité légale complète des deux parties)
    seller_snapshot jsonb,
    client_snapshot jsonb,
    pdf_file_id     uuid REFERENCES files (id) ON DELETE SET NULL,
    validated_by    uuid REFERENCES users (id) ON DELETE SET NULL,
    validated_at    timestamptz,
    sent_at         timestamptz,
    decided_at      timestamptz,                        -- acceptation ou refus
    created_by      uuid REFERENCES users (id) ON DELETE SET NULL,
    updated_by      uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz,                        -- brouillons uniquement (règle app)
    CONSTRAINT quotes_validated_have_number
      CHECK (status = 'draft' OR (number IS NOT NULL AND fiscal_year_id IS NOT NULL))
);
CREATE UNIQUE INDEX quotes_number_unique
  ON quotes (company_id, number) WHERE number IS NOT NULL;
CREATE INDEX quotes_company_status_idx ON quotes (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX quotes_company_client_idx ON quotes (company_id, client_id);
CREATE INDEX quotes_company_date_idx   ON quotes (company_id, issue_date);

CREATE TABLE quote_items (
    id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id     uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    quote_id       uuid NOT NULL REFERENCES quotes (id) ON DELETE CASCADE,
    product_id     uuid REFERENCES products (id) ON DELETE SET NULL, -- NULL = ligne libre
    position       smallint NOT NULL DEFAULT 0,
    label          varchar(255) NOT NULL,               -- copié du produit, éditable
    description    text,
    quantity       numeric(14,3) NOT NULL CONSTRAINT quote_items_qty_check CHECK (quantity > 0),
    unit           varchar(20) NOT NULL DEFAULT 'unité',
    unit_price_ht  bigint NOT NULL,
    discount_rate  numeric(5,2) NOT NULL DEFAULT 0
                   CONSTRAINT quote_items_discount_check CHECK (discount_rate BETWEEN 0 AND 100),
    -- TVA : FK conservée pour l'édition, mais le TAUX est copié ici — c'est lui
    -- qui fait foi (immuabilité même si tax_rates change ensuite).
    tax_rate_id    uuid REFERENCES tax_rates (id) ON DELETE SET NULL,
    tax_rate       numeric(5,2) NOT NULL DEFAULT 0,
    -- Totaux de ligne en centimes, arrondi commercial par ligne.
    total_ht       bigint NOT NULL DEFAULT 0,
    tax_amount     bigint NOT NULL DEFAULT 0,
    total_ttc      bigint NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX quote_items_quote_idx ON quote_items (quote_id, position);

-- ============================================================================
-- FACTURES
-- ============================================================================

CREATE TABLE invoices (
    id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id          uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    client_id           uuid NOT NULL REFERENCES clients (id) ON DELETE RESTRICT,
    quote_id            uuid REFERENCES quotes (id) ON DELETE SET NULL,   -- devis d'origine
    fiscal_year_id      uuid REFERENCES fiscal_years (id) ON DELETE RESTRICT,
    number              varchar(30),
    status              varchar(15) NOT NULL DEFAULT 'draft'
                        CONSTRAINT invoices_status_check CHECK (status IN
                          ('draft','validated','sent','partially_paid','paid','cancelled')),
    issue_date          date NOT NULL DEFAULT CURRENT_DATE,
    due_date            date NOT NULL DEFAULT CURRENT_DATE,   -- "en retard" dérivé de due_date
    currency            char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    exchange_rate       numeric(18,8) NOT NULL DEFAULT 1
                        CONSTRAINT invoices_rate_check CHECK (exchange_rate > 0),
    subtotal_ht         bigint NOT NULL DEFAULT 0,
    total_discount      bigint NOT NULL DEFAULT 0,
    total_vat           bigint NOT NULL DEFAULT 0,
    total_ttc           bigint NOT NULL DEFAULT 0,
    -- Retenue à la source (prestations) : taux paramétré, montant figé à la validation
    withholding_rate    numeric(5,2) NOT NULL DEFAULT 0,
    withholding_amount  bigint NOT NULL DEFAULT 0,
    -- Somme des allocations de paiements ; maintenue par le service Paiements
    amount_paid         bigint NOT NULL DEFAULT 0
                        CONSTRAINT invoices_paid_check CHECK (amount_paid >= 0),
    notes               text,
    internal_notes      text,
    seller_snapshot     jsonb,
    client_snapshot     jsonb,
    pdf_file_id         uuid REFERENCES files (id) ON DELETE SET NULL,
    validated_by        uuid REFERENCES users (id) ON DELETE SET NULL,
    validated_at        timestamptz,
    sent_at             timestamptz,
    paid_at             timestamptz,
    cancelled_at        timestamptz,
    created_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    updated_by          uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    deleted_at          timestamptz,                    -- brouillons uniquement (trigger + règle app)
    CONSTRAINT invoices_validated_have_number
      CHECK (status = 'draft' OR (number IS NOT NULL AND fiscal_year_id IS NOT NULL))
);
CREATE UNIQUE INDEX invoices_number_unique
  ON invoices (company_id, number) WHERE number IS NOT NULL;
CREATE INDEX invoices_company_status_idx ON invoices (company_id, status) WHERE deleted_at IS NULL;
CREATE INDEX invoices_company_client_idx ON invoices (company_id, client_id);
CREATE INDEX invoices_company_issue_idx  ON invoices (company_id, issue_date);
-- Impayés : parcours fréquent (relances, dashboard) → index partiel ciblé
CREATE INDEX invoices_unpaid_due_idx     ON invoices (company_id, due_date)
  WHERE status IN ('validated','sent','partially_paid');

CREATE TABLE invoice_items (
    id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id     uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    invoice_id     uuid NOT NULL REFERENCES invoices (id) ON DELETE CASCADE,
    product_id     uuid REFERENCES products (id) ON DELETE SET NULL,
    position       smallint NOT NULL DEFAULT 0,
    label          varchar(255) NOT NULL,
    description    text,
    quantity       numeric(14,3) NOT NULL CONSTRAINT invoice_items_qty_check CHECK (quantity > 0),
    unit           varchar(20) NOT NULL DEFAULT 'unité',
    unit_price_ht  bigint NOT NULL,
    discount_rate  numeric(5,2) NOT NULL DEFAULT 0
                   CONSTRAINT invoice_items_discount_check CHECK (discount_rate BETWEEN 0 AND 100),
    tax_rate_id    uuid REFERENCES tax_rates (id) ON DELETE SET NULL,
    tax_rate       numeric(5,2) NOT NULL DEFAULT 0,
    total_ht       bigint NOT NULL DEFAULT 0,
    tax_amount     bigint NOT NULL DEFAULT 0,
    total_ttc      bigint NOT NULL DEFAULT 0,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX invoice_items_invoice_idx ON invoice_items (invoice_id, position);
-- Le récapitulatif TVA par taux (pied de document) est calculé par
-- GROUP BY tax_rate sur les items — pas de table dédiée nécessaire.

-- ============================================================================
-- AVOIRS (notes de crédit) — proposés en Phase 1, cf. critique §1.1
-- ============================================================================

CREATE TABLE credit_notes (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    client_id       uuid NOT NULL REFERENCES clients (id) ON DELETE RESTRICT,
    -- Rattachement légal obligatoire à la facture d'origine
    invoice_id      uuid NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
    fiscal_year_id  uuid REFERENCES fiscal_years (id) ON DELETE RESTRICT,
    number          varchar(30),
    status          varchar(12) NOT NULL DEFAULT 'draft'
                    CONSTRAINT credit_notes_status_check CHECK (status IN
                      ('draft','validated','settled')),
    -- settlement : comment l'avoir est soldé — imputation sur la facture,
    -- remboursement, ou imputation sur une facture future (avoir en compte)
    settlement_kind varchar(15)
                    CONSTRAINT credit_notes_settlement_check CHECK (settlement_kind IN
                      ('applied','refunded','on_account')),
    reason          text NOT NULL,                      -- motif obligatoire (contrôle fiscal)
    issue_date      date NOT NULL DEFAULT CURRENT_DATE,
    currency        char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    exchange_rate   numeric(18,8) NOT NULL DEFAULT 1,
    subtotal_ht     bigint NOT NULL DEFAULT 0,          -- montants POSITIFS ;
    total_vat       bigint NOT NULL DEFAULT 0,          -- le sens (crédit) est porté
    total_ttc       bigint NOT NULL DEFAULT 0,          -- par la nature du document
    seller_snapshot jsonb,
    client_snapshot jsonb,
    pdf_file_id     uuid REFERENCES files (id) ON DELETE SET NULL,
    validated_by    uuid REFERENCES users (id) ON DELETE SET NULL,
    validated_at    timestamptz,
    settled_at      timestamptz,
    created_by      uuid REFERENCES users (id) ON DELETE SET NULL,
    updated_by      uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    deleted_at      timestamptz,
    CONSTRAINT credit_notes_validated_have_number
      CHECK (status = 'draft' OR (number IS NOT NULL AND fiscal_year_id IS NOT NULL))
);
CREATE UNIQUE INDEX credit_notes_number_unique
  ON credit_notes (company_id, number) WHERE number IS NOT NULL;
CREATE INDEX credit_notes_invoice_idx ON credit_notes (company_id, invoice_id);

CREATE TABLE credit_note_items (
    id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id      uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    credit_note_id  uuid NOT NULL REFERENCES credit_notes (id) ON DELETE CASCADE,
    -- ligne de facture corrigée (nullable : avoir global, ex. remise a posteriori)
    invoice_item_id uuid REFERENCES invoice_items (id) ON DELETE SET NULL,
    position        smallint NOT NULL DEFAULT 0,
    label           varchar(255) NOT NULL,
    quantity        numeric(14,3) NOT NULL CONSTRAINT cn_items_qty_check CHECK (quantity > 0),
    unit            varchar(20) NOT NULL DEFAULT 'unité',
    unit_price_ht   bigint NOT NULL,
    tax_rate        numeric(5,2) NOT NULL DEFAULT 0,
    total_ht        bigint NOT NULL DEFAULT 0,
    tax_amount      bigint NOT NULL DEFAULT 0,
    total_ttc       bigint NOT NULL DEFAULT 0,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX credit_note_items_cn_idx ON credit_note_items (credit_note_id, position);

-- ============================================================================
-- PAIEMENTS & ALLOCATIONS
-- Un paiement (chèque, virement…) peut solder plusieurs factures, et une
-- facture peut être réglée en plusieurs fois → table d'allocation N:N.
-- ============================================================================

CREATE TABLE payments (
    id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id         uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    client_id          uuid NOT NULL REFERENCES clients (id) ON DELETE RESTRICT,
    payment_method_id  uuid NOT NULL REFERENCES payment_methods (id) ON DELETE RESTRICT,
    bank_account_id    uuid REFERENCES bank_accounts (id) ON DELETE SET NULL, -- NULL si espèces
    reference          varchar(100),                    -- n° chèque, réf. virement…
    amount             bigint NOT NULL CONSTRAINT payments_amount_check CHECK (amount > 0),
    currency           char(3) NOT NULL DEFAULT 'MAD' REFERENCES currencies (code),
    exchange_rate      numeric(18,8) NOT NULL DEFAULT 1,
    -- Timbre fiscal : dû sur les règlements en ESPÈCES au-delà du seuil légal.
    -- Taux et seuil lus dans settings (paramétrables) ; montant figé ici.
    stamp_duty_amount  bigint NOT NULL DEFAULT 0,
    received_at        date NOT NULL DEFAULT CURRENT_DATE,
    notes              text,
    created_by         uuid REFERENCES users (id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    deleted_at         timestamptz                      -- annulation d'une saisie erronée,
                                                        -- tracée en audit ; jamais de DELETE dur
);
CREATE INDEX payments_company_date_idx   ON payments (company_id, received_at);
CREATE INDEX payments_company_client_idx ON payments (company_id, client_id);

CREATE TABLE payment_allocations (
    id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
    company_id  uuid NOT NULL REFERENCES companies (id) ON DELETE CASCADE,
    payment_id  uuid NOT NULL REFERENCES payments (id) ON DELETE CASCADE,
    invoice_id  uuid NOT NULL REFERENCES invoices (id) ON DELETE RESTRICT,
    amount      bigint NOT NULL CONSTRAINT allocations_amount_check CHECK (amount > 0),
    created_at  timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT payment_allocations_unique UNIQUE (payment_id, invoice_id)
);
CREATE INDEX payment_allocations_invoice_idx ON payment_allocations (company_id, invoice_id);
-- Invariants applicatifs (Service Paiements, sous DB::transaction) :
--   SUM(allocations.amount) par payment  <= payment.amount
--   SUM(allocations.amount) par invoice  == invoice.amount_paid
--   amount_paid <= total_ttc - withholding_amount

-- ============================================================================
-- IMMUABILITÉ — défense en profondeur côté base
-- L'application (Policies + Actions) est la première ligne ; ce trigger rend
-- le contournement impossible, y compris via une faille applicative.
-- Colonnes autorisées après validation : cycle de vie uniquement.
-- ============================================================================

CREATE OR REPLACE FUNCTION enforce_document_immutability() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  IF OLD.status <> 'draft' THEN
    IF TG_OP = 'DELETE' THEN
      RAISE EXCEPTION 'Document % validé : suppression interdite (créer un avoir)', OLD.id;
    END IF;
    -- Seules les colonnes de cycle de vie peuvent changer après validation
    IF (to_jsonb(NEW) - ARRAY['status','amount_paid','sent_at','paid_at','cancelled_at',
                              'decided_at','settled_at','settlement_kind','pdf_file_id',
                              'internal_notes','updated_by','updated_at'])
       IS DISTINCT FROM
       (to_jsonb(OLD) - ARRAY['status','amount_paid','sent_at','paid_at','cancelled_at',
                              'decided_at','settled_at','settlement_kind','pdf_file_id',
                              'internal_notes','updated_by','updated_at']) THEN
      RAISE EXCEPTION 'Document % validé : contenu immuable (créer un avoir)', OLD.id;
    END IF;
    IF NEW.deleted_at IS NOT NULL THEN
      RAISE EXCEPTION 'Document % validé : soft delete interdit', OLD.id;
    END IF;
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;

CREATE TRIGGER invoices_immutability
  BEFORE UPDATE OR DELETE ON invoices
  FOR EACH ROW EXECUTE FUNCTION enforce_document_immutability();
CREATE TRIGGER quotes_immutability
  BEFORE UPDATE OR DELETE ON quotes
  FOR EACH ROW EXECUTE FUNCTION enforce_document_immutability();
CREATE TRIGGER credit_notes_immutability
  BEFORE UPDATE OR DELETE ON credit_notes
  FOR EACH ROW EXECUTE FUNCTION enforce_document_immutability();

-- Les items d'un document non-brouillon sont verrouillés en bloc.
-- Un trigger par table d'items : le nom de la colonne FK diffère, et plpgsql
-- ne permet pas d'y accéder dynamiquement de façon propre.

CREATE OR REPLACE FUNCTION enforce_invoice_items_immutability() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
  SELECT status INTO parent_status FROM invoices
    WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
  IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
    RAISE EXCEPTION 'Facture validée : lignes immuables (créer un avoir)';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER invoice_items_immutability
  BEFORE INSERT OR UPDATE OR DELETE ON invoice_items
  FOR EACH ROW EXECUTE FUNCTION enforce_invoice_items_immutability();

CREATE OR REPLACE FUNCTION enforce_quote_items_immutability() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
  SELECT status INTO parent_status FROM quotes
    WHERE id = COALESCE(NEW.quote_id, OLD.quote_id);
  IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
    RAISE EXCEPTION 'Devis validé : lignes immuables';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER quote_items_immutability
  BEFORE INSERT OR UPDATE OR DELETE ON quote_items
  FOR EACH ROW EXECUTE FUNCTION enforce_quote_items_immutability();

CREATE OR REPLACE FUNCTION enforce_credit_note_items_immutability() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE parent_status text;
BEGIN
  SELECT status INTO parent_status FROM credit_notes
    WHERE id = COALESCE(NEW.credit_note_id, OLD.credit_note_id);
  IF parent_status IS NOT NULL AND parent_status <> 'draft' THEN
    RAISE EXCEPTION 'Avoir validé : lignes immuables';
  END IF;
  IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER credit_note_items_immutability
  BEFORE INSERT OR UPDATE OR DELETE ON credit_note_items
  FOR EACH ROW EXECUTE FUNCTION enforce_credit_note_items_immutability();

-- ============================================================================
-- ROW LEVEL SECURITY — mêmes règles que la Phase 0
-- ============================================================================

DO $$
DECLARE t text;
BEGIN
  FOREACH t IN ARRAY ARRAY[
    'clients','categories','products','bank_accounts','payment_methods',
    'quotes','quote_items','invoices','invoice_items',
    'credit_notes','credit_note_items','payments','payment_allocations'
  ] LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
    EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', t);
    EXECUTE format(
      'CREATE POLICY tenant_isolation ON %I
         USING (company_id = NULLIF(current_setting(''app.company_id'', true), '''')::uuid)
         WITH CHECK (company_id = NULLIF(current_setting(''app.company_id'', true), '''')::uuid)', t);
  END LOOP;
END $$;
