<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La SITUATION devient le 9ᵉ type de `documents`.
 *
 * Décision du 2026-08-05, dans la ligne de l'arbitrage du 2026-07-21 (« une
 * table unique, pas une table par type ») : une situation porte un numéro, une
 * date, un tiers, un montant et un état de règlement — soit exactement
 * l'en-tête d'un document commercial. Une table dédiée aurait dupliqué la
 * numérotation atomique, l'immuabilité, l'instantané légal du client et les
 * policies RLS, pour ne rien ajouter de neuf.
 *
 * Deux colonnes manquaient, ajoutées ici :
 *
 * - `subject` — l'objet de la situation (« Situation du mois d'octobre »).
 *   Colonne d'EN-TÊTE et non ligne de détail : la liste l'affiche et le
 *   recherche sans jointure sur `document_items`.
 *
 * - `paid_cents` — le montant déjà réglé (« avance »). C'est un total
 *   DÉNORMALISÉ, au même titre que `tax_cents` ou `discount_cents` : saisi à la
 *   main aujourd'hui, il sera RECALCULÉ depuis les règlements quand le module
 *   Encaissements arrivera, sans migration de données ni changement de contrat.
 *
 * L'état de règlement (« non payé / partiel / payé ») n'a PAS de colonne : il se
 * déduit de `paid_cents` face à `total_cents` et alimente `status`. Le stocker
 * en double, c'est accepter qu'un jour les deux se contredisent.
 */
return new class extends Migration
{
    /** Types de documents, situation comprise. Miroir de `DocumentType`. */
    private const TYPES = [
        'invoice', 'quote', 'proforma', 'purchase_order',
        'delivery_note', 'shipping_slip', 'credit_note',
        'purchase_invoice', 'situation',
    ];

    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('subject')->nullable()->after('client_address');

            // Montant déjà encaissé. NOT NULL avec défaut à 0 : « aucune
            // avance » et « avance nulle » sont la même chose, et un NULL
            // obligerait chaque calcul de reste à dû à le coalescer.
            $table->bigInteger('paid_cents')->default(0)->after('total_cents');

            // L'écran « situations par client » filtre sur (société, type,
            // tiers). L'index existant s'arrête à (company_id, partner_id) et
            // ferait remonter aussi les factures du client.
            $table->index(['company_id', 'type', 'partner_id']);
        });

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement($this->typeCheck('documents', 'type', self::TYPES));

        // La séquence de numérotation doit accepter le nouveau type, sinon
        // l'émission de la première situation échoue sur la contrainte.
        DB::statement('ALTER TABLE sequences DROP CONSTRAINT sequences_doc_type_check');
        DB::statement($this->typeCheck('sequences', 'document_type', self::TYPES));

        // Une avance négative n'est pas un remboursement : c'est une saisie
        // fausse. Le remboursement se traite par avoir (§3).
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_paid_positive_check CHECK (paid_cents >= 0)');

        // On ne peut pas avoir encaissé plus que dû. La règle est posée EN BASE
        // et pas seulement dans le FormRequest : elle protège aussi les
        // écritures futures du module Encaissements, qui ne passeront pas par
        // le même chemin de validation.
        DB::statement(<<<'SQL'
            ALTER TABLE documents
              ADD CONSTRAINT documents_paid_not_above_total_check
              CHECK (paid_cents <= total_cents)
        SQL);

        $this->openSituationSequences();
    }

    public function down(): void
    {
        // Les situations émises portent un numéro de séquence : retirer le type
        // les rendrait invalides au regard de la contrainte. On les supprime
        // donc explicitement avant, plutôt que de laisser la migration échouer
        // à mi-parcours sur une base qui en contient.
        DB::table('documents')->where('type', 'situation')->delete();
        DB::table('sequences')->where('document_type', 'situation')->delete();

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_paid_not_above_total_check');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_paid_positive_check');

        $previous = array_values(array_diff(self::TYPES, ['situation']));

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_type_check');
        DB::statement($this->typeCheck('documents', 'type', $previous));

        DB::statement('ALTER TABLE sequences DROP CONSTRAINT sequences_doc_type_check');
        DB::statement($this->typeCheck('sequences', 'document_type', $previous));

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'type', 'partner_id']);
            $table->dropColumn(['subject', 'paid_cents']);
        });
    }

    /**
     * Contrainte CHECK d'énumération. Les valeurs viennent d'une constante de
     * classe, jamais d'une entrée externe — aucune interpolation à risque.
     *
     * @param  list<string>  $values
     */
    private function typeCheck(string $table, string $column, array $values): string
    {
        $constraint = $table === 'sequences' ? 'sequences_doc_type_check' : 'documents_type_check';
        $list = implode(',', array_map(static fn (string $v): string => "'{$v}'", $values));

        return "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$list}))";
    }

    /**
     * Ouvre la séquence `situation` sur l'exercice courant de chaque société.
     *
     * Même raison que `backfill_fiscal_years_and_sequences` : le provisioning
     * ne couvre que les sociétés créées ENSUITE. Sans cette reprise, une société
     * existante verrait sa première situation échouer — ou plus exactement
     * ouvrirait la séquence à la volée via DocumentNumberService, mais avec le
     * format par défaut et sans que l'administrateur l'ait vue arriver.
     */
    private function openSituationSequences(): void
    {
        $now = Carbon::now();

        $fiscalYears = DB::table('fiscal_years')
            ->where('status', 'open')
            ->get(['id', 'company_id']);

        foreach ($fiscalYears as $fiscalYear) {
            $alreadyOpen = DB::table('sequences')
                ->where('fiscal_year_id', $fiscalYear->id)
                ->where('document_type', 'situation')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            DB::table('sequences')->insert([
                'company_id' => $fiscalYear->company_id,
                'fiscal_year_id' => $fiscalYear->id,
                'document_type' => 'situation',
                'format' => 'SIT-{YYYY}-{0000}',
                'next_number' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
