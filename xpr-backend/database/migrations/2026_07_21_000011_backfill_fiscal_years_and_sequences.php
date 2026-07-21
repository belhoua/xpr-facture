<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre l'exercice courant et ses séquences pour les sociétés créées AVANT
 * l'arrivée du module Accounting.
 *
 * Sans cette reprise, une société existante n'a ni exercice ni compteur : la
 * validation d'une facture y échoue sur NoFiscalYearForDate. Le provisioning
 * ne couvre que les sociétés créées après, d'où cette migration de données —
 * elle vaut pour le dépôt de développement comme pour la production.
 *
 * `next_number` est calé sur les factures DÉJÀ numérotées de l'exercice, pour
 * que la première facture émise ensuite ne retombe pas sur un numéro pris.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $label = $now->format('Y');

        $companies = DB::table('companies')
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($companies as $companyId) {
            $companyId = (string) $companyId;

            $existing = DB::table('fiscal_years')
                ->where('company_id', $companyId)
                ->where('label', $label)
                ->value('id');

            if (is_string($existing)) {
                $fiscalYearId = $existing;
            } else {
                $fiscalYearId = (string) DB::table('fiscal_years')->insertGetId([
                    'company_id' => $companyId,
                    'label' => $label,
                    'starts_on' => $now->copy()->startOfYear()->toDateString(),
                    'ends_on' => $now->copy()->endOfYear()->toDateString(),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ], 'id');
            }

            $this->openSequences($companyId, $fiscalYearId, $label, $now);
        }
    }

    /**
     * `down()` volontairement vide : supprimer des exercices et des séquences
     * détruirait la continuité de numérotation des factures qui s'y rattachent.
     * Un rollback ne doit pas être plus destructeur que la migration.
     */
    public function down(): void {}

    private function openSequences(
        string $companyId,
        string $fiscalYearId,
        string $label,
        Carbon $now,
    ): void {
        $formats = [
            'invoice' => 'FAC-{YYYY}-{0000}',
            'quote' => 'DEV-{YYYY}-{0000}',
            'credit_note' => 'AV-{YYYY}-{0000}',
        ];

        foreach ($formats as $type => $format) {
            $alreadyOpen = DB::table('sequences')
                ->where('company_id', $companyId)
                ->where('fiscal_year_id', $fiscalYearId)
                ->where('document_type', $type)
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            DB::table('sequences')->insert([
                'company_id' => $companyId,
                'fiscal_year_id' => $fiscalYearId,
                'document_type' => $type,
                'format' => $format,
                'next_number' => $type === 'invoice'
                    ? $this->nextInvoiceNumber($companyId, $label)
                    : 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Rang suivant le plus haut numéro déjà attribué sur l'exercice.
     *
     * On lit le SUFFIXE des numéros existants plutôt que de compter les lignes :
     * une facture supprimée laisse un numéro consommé, que §3 interdit de
     * réattribuer. Compter aurait rendu ce numéro à la facture suivante.
     */
    private function nextInvoiceNumber(string $companyId, string $label): int
    {
        $prefix = "FAC-{$label}-";

        $highest = DB::table('invoices')
            ->where('company_id', $companyId)
            ->where('number', 'like', $prefix.'%')
            ->selectRaw('MAX(NULLIF(regexp_replace(number, \'^.*-\', \'\'), \'\')::int) AS max_rank')
            ->value('max_rank');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
    }
};
