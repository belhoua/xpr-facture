<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache la facture au répertoire des tiers.
 *
 * `client_name` EST CONSERVÉ, et ce n'est pas une redondance à nettoyer plus
 * tard : c'est l'instantané légal du nom porté par le document au moment de son
 * émission. Une facture validée est immuable (§3) — si le tiers change de
 * raison sociale, ou si sa fiche est archivée, les factures déjà émises doivent
 * continuer d'afficher exactement ce qui a été envoyé au client. Résoudre le
 * nom par jointure à l'affichage réécrirait l'histoire à chaque renommage.
 *
 * Le couple est donc : `partner_id` pour AGRÉGER et NAVIGUER, `client_name`
 * pour RESTITUER le document.
 *
 * Nullable : les factures antérieures n'ont pas de tiers, et le rapprochement
 * par nom ci-dessous n'aboutit pas toujours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            // RESTRICT : un tiers référencé par une facture ne doit pas pouvoir
            // disparaître. L'archivage (soft delete) reste possible, lui.
            $table->foreignUuid('partner_id')
                ->nullable()
                ->after('company_id')
                ->constrained('partners')
                ->restrictOnDelete();

            $table->index(['company_id', 'partner_id']);
        });

        $this->linkExistingInvoices();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('partner_id');
        });
    }

    /**
     * Rapproche les factures existantes du répertoire, par nom EXACT et à
     * l'intérieur de la même société.
     *
     * Volontairement conservateur : ni approximation, ni insensibilité à la
     * casse, ni rapprochement partiel. Un faux positif rattacherait une facture
     * au mauvais client et fausserait durablement ses agrégats ; une facture
     * non rapprochée reste simplement à NULL et se regroupe par son nom, comme
     * avant. On ne rapproche que ce dont on est certain.
     */
    private function linkExistingInvoices(): void
    {
        DB::statement(<<<'SQL'
            UPDATE invoices AS i
               SET partner_id = p.id
              FROM partners AS p
             WHERE p.company_id = i.company_id
               AND p.deleted_at IS NULL
               AND i.partner_id IS NULL
               AND i.client_name IN (p.legal_name, p.trade_name)
               -- Un nom ambigu dans la société (deux fiches homonymes) est
               -- laissé de côté : mieux vaut aucun lien qu'un lien arbitraire.
               AND (
                   SELECT count(*) FROM partners AS d
                    WHERE d.company_id = i.company_id
                      AND d.deleted_at IS NULL
                      AND i.client_name IN (d.legal_name, d.trade_name)
               ) = 1
        SQL);
    }
};
