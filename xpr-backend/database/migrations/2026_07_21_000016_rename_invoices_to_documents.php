<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `invoices` devient `documents` : le moteur unique des documents commerciaux.
 *
 * ARBITRAGE (2026-07-21, consigné dans la mémoire projet) : les 8 types de
 * documents — devis, facture, proforma, bon de commande, bon de livraison,
 * fiche d'expédition, avoir, facture d'achat — vivent dans UNE table avec un
 * discriminant `type`, et non dans une table par type. Écart assumé au §7 de
 * CLAUDE.md, qui listait `credit_notes`, `delivery_notes`, `purchase_orders`
 * séparément.
 *
 * Pourquoi : les 8 types partagent ~90 % de leur structure (en-tête tiers,
 * lignes, TVA par ligne, totaux, statuts) et ne diffèrent que par la
 * numérotation, le workflow d'état et les mentions légales. Neuf tables, ce
 * serait neuf implémentations de la règle de TVA et de l'immuabilité — donc
 * neuf endroits où une évolution de la réglementation peut être oubliée.
 * Corollaire décisif pour l'étape D : la conversion devis → facture et la
 * création d'un avoir deviennent une COPIE DE LIGNES au sein d'une même table,
 * pas une traduction entre deux schémas.
 *
 * RENAME et non CREATE + DROP : la table portait déjà les factures de
 * démonstration et les données de développement. `ALTER TABLE … RENAME`
 * conserve les lignes, les index, les contraintes de clé étrangère et — point
 * important — la POLICY RLS, qui suit la table et n'a pas à être réappliquée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('invoices', 'documents');

        // Les objets nommés d'après l'ancienne table ne sont pas renommés par
        // PostgreSQL : on le fait, sinon `documents` traîne des contraintes
        // « invoices_* » que personne ne saura relire dans un an.
        DB::statement('ALTER TABLE documents RENAME CONSTRAINT invoices_company_id_foreign TO documents_company_id_foreign');
        DB::statement('ALTER TABLE documents RENAME CONSTRAINT invoices_partner_id_foreign TO documents_partner_id_foreign');
        DB::statement('ALTER INDEX invoices_pkey RENAME TO documents_pkey');
        DB::statement('ALTER INDEX invoices_company_number_unique RENAME TO documents_company_number_unique');

        Schema::table('documents', function (Blueprint $table): void {
            // Discriminant. `invoice` par défaut : toutes les lignes déjà en
            // base sont des factures, la valeur les qualifie sans backfill.
            $table->string('type', 20)->default('invoice')->after('company_id');

            // Rattachement d'un document à celui dont il découle : l'avoir à sa
            // facture d'origine, la facture au devis qu'elle concrétise. Une
            // seule colonne pour les deux liens — c'est toujours la même
            // relation « ce document existe à cause de celui-là ».
            // RESTRICT : la chaîne documentaire est une pièce comptable, on ne
            // supprime pas le maillon qui explique le suivant.
            $table->foreignUuid('parent_document_id')
                ->nullable()
                ->after('partner_id')
                ->constrained('documents')
                ->restrictOnDelete();

            // Instantané légal du tiers, au même titre que `client_name` (§3) :
            // une facture validée doit continuer d'afficher l'ICE et l'adresse
            // qui figuraient sur l'exemplaire envoyé au client, même si la
            // fiche du tiers a changé depuis.
            $table->char('client_ice', 15)->nullable()->after('client_name');
            $table->text('client_address')->nullable()->after('client_ice');

            // Totaux DÉNORMALISÉS, recalculés depuis les lignes à chaque
            // écriture par DocumentCalculator. Ils ne sont pas une commodité
            // d'affichage : le dashboard et les états agrègent des milliers de
            // documents, et refaire la somme des lignes à chaque KPI coûterait
            // une jointure sur toute la table `document_items`.
            // `total_cents` existe déjà (TTC).
            $table->bigInteger('subtotal_cents')->default(0)->after('status');
            $table->bigInteger('discount_cents')->default(0)->after('subtotal_cents');
            $table->bigInteger('tax_cents')->default(0)->after('discount_cents');

            $table->text('notes')->nullable()->after('currency');
            $table->text('terms')->nullable()->after('notes');

            $table->index(['company_id', 'type', 'status']);
            $table->index(['company_id', 'type', 'issued_at']);
            $table->index(['company_id', 'parent_document_id']);
        });

        // `total_cents` avait été créé SANS défaut, du temps où le montant était
        // saisi à la main dans l'en-tête. Le moteur le CALCULE désormais depuis
        // les lignes, et un document naît vide : sans défaut, l'insertion du
        // brouillon échouerait sur la contrainte NOT NULL avant même qu'une
        // ligne n'existe. Zéro est la bonne valeur — un document sans ligne
        // vaut zéro, ce n'est pas une donnée manquante.
        DB::statement('ALTER TABLE documents ALTER COLUMN total_cents SET DEFAULT 0');

        // Les lignes antérieures au moteur n'ont pas de `document_items` : leur
        // seul montant connu est le TTC. On aligne le HT dessus plutôt que de
        // laisser 0, qui afficherait « TTC 12 000 / HT 0 » — visiblement faux.
        // La ventilation de TVA réelle n'existera que pour les documents créés
        // par le moteur, qui portent leurs lignes.
        DB::statement('UPDATE documents SET subtotal_cents = total_cents WHERE subtotal_cents = 0');

        DB::statement('ALTER TABLE documents DROP CONSTRAINT invoices_status_check');
        DB::statement(<<<'SQL'
            ALTER TABLE documents
              ADD CONSTRAINT documents_status_check
              CHECK (status IN (
                'draft','sent','accepted','refused','converted',
                'partial','paid','overdue','cancelled'
              ))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE documents
              ADD CONSTRAINT documents_type_check
              CHECK (type IN (
                'invoice','quote','proforma','purchase_order',
                'delivery_note','shipping_slip','credit_note','purchase_invoice'
              ))
        SQL);

        // Un document ne peut pas découler de lui-même. Garde-fou minimal
        // contre le cycle : la profondeur réelle (devis → facture → avoir) est
        // contrôlée applicativement par DocumentConversionService.
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_parent_not_self_check CHECK (parent_document_id IS NULL OR parent_document_id <> id)');
    }

    public function down(): void
    {
        // Les documents qui ne sont pas des factures n'ont pas leur place dans
        // une table `invoices` : on refuse de rétrograder une base qui en
        // contient, plutôt que de les perdre silencieusement.
        $foreign = (int) DB::table('documents')->where('type', '!=', 'invoice')->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                "Retour arrière impossible : {$foreign} document(s) non-facture seraient perdus.",
            );
        }

        // Les CHECK d'abord : PostgreSQL les supprimerait en cascade avec leur
        // colonne, et le DROP CONSTRAINT explicite échouerait ensuite.
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_status_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_parent_not_self_check');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'type', 'status']);
            $table->dropIndex(['company_id', 'type', 'issued_at']);
            $table->dropIndex(['company_id', 'parent_document_id']);
            $table->dropConstrainedForeignId('parent_document_id');
            $table->dropColumn([
                'type', 'client_ice', 'client_address',
                'subtotal_cents', 'discount_cents', 'tax_cents', 'notes', 'terms',
            ]);
        });

        DB::statement('ALTER INDEX documents_company_number_unique RENAME TO invoices_company_number_unique');
        DB::statement('ALTER INDEX documents_pkey RENAME TO invoices_pkey');
        DB::statement('ALTER TABLE documents RENAME CONSTRAINT documents_company_id_foreign TO invoices_company_id_foreign');
        DB::statement('ALTER TABLE documents RENAME CONSTRAINT documents_partner_id_foreign TO invoices_partner_id_foreign');

        Schema::rename('documents', 'invoices');

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
              ADD CONSTRAINT invoices_status_check
              CHECK (status IN ('draft','sent','partial','paid','overdue','cancelled'))
        SQL);
    }
};
