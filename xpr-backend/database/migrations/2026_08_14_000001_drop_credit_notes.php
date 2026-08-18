<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retrait de l'AVOIR (`credit_note`) du produit — décision de l'exploitant du
 * 2026-08-13, prise après que le coût lui a été exposé.
 *
 * ── Ce que la décision coûte, dit ici une fois pour toutes ──────────────────
 *
 * L'avoir était l'instrument par lequel le §3 fait corriger une facture émise :
 * la facture reste intacte, une pièce de sens inverse lui est rattachée. En le
 * retirant, le produit n'offre plus AUCUN moyen de matérialiser la correction
 * d'une facture — l'édition directe (levée du 2026-08-06) devient le seul
 * chemin, et elle ne laisse d'autre trace que `updated_at`. C'est précisément
 * l'écart qu'un contrôle fiscal vient chercher. Le CLAUDE.md §3 et §7 décrivent
 * toujours la règle d'origine : ils énoncent la cible, pas l'état.
 *
 * ── Pourquoi un DELETE et non un soft delete ───────────────────────────────
 *
 * Les deux ont été présentés à l'exploitant le 2026-08-14 ; il a tranché pour
 * la suppression définitive. Conséquence à connaître : l'avoir `AV-2026-0001`,
 * déjà émis et numéroté, disparaît de la base. Aucune ligne ne dira plus ce
 * qu'il portait — la copie remise au client, si elle existe, n'a plus de
 * contrepartie ici.
 *
 * ── Ordre des opérations, imposé par les contraintes ───────────────────────
 *
 *  1. `documents.parent_document_id` est en ON DELETE **RESTRICT** : un
 *     document qui descendrait d'un avoir bloquerait la suppression. On coupe
 *     donc le lien d'abord. (`conventions.source_document_id` est en SET NULL
 *     et `document_items.document_id` en CASCADE : ceux-là se règlent seuls.)
 *  2. Suppression des avoirs, soft-deletés compris — `deleted_at` ne protège
 *     de rien ici, la demande porte sur le type entier.
 *  3. Suppression des séquences `AV-`, devenues sans objet : plus rien ne peut
 *     y puiser un numéro.
 *  4. Resserrement des deux contraintes CHECK. Sûr seulement parce que (2) et
 *     (3) ont déjà vidé les lignes concernées — PostgreSQL valide l'existant
 *     en posant la contrainte, l'ordre n'est donc pas cosmétique.
 */
return new class extends Migration
{
    /** Miroir de `Accounting\Enums\DocumentType`, `credit_note` en moins. */
    private const TYPES = [
        'invoice', 'quote', 'proforma', 'purchase_order',
        'delivery_note', 'shipping_slip', 'purchase_invoice', 'situation',
    ];

    /** @var array<string, string> table => colonne discriminante */
    private const TARGETS = [
        'sequences' => 'document_type',
        'documents' => 'type',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $creditNotes = DB::table('documents')
                ->where('type', 'credit_note')
                ->pluck('id');

            if ($creditNotes->isNotEmpty()) {
                DB::table('documents')
                    ->whereIn('parent_document_id', $creditNotes)
                    ->update(['parent_document_id' => null]);

                DB::table('documents')->whereIn('id', $creditNotes)->delete();
            }

            DB::table('sequences')->where('document_type', 'credit_note')->delete();

            foreach (self::TARGETS as $table => $column) {
                $constraint = self::constraintName($table);
                $values = implode(',', array_map(
                    static fn (string $type): string => "'{$type}'",
                    self::TYPES,
                ));

                // IF EXISTS : la contrainte porte un nom historique qui a pu
                // diverger, et une base déjà traitée doit rester rejouable.
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
                DB::statement(
                    "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$values}))"
                );
            }
        });
    }

    /**
     * `down()` ne rétablit que les CONTRAINTES, jamais les données.
     *
     * Un rollback qui ressusciterait des avoirs supprimés n'existe pas : les
     * lignes sont parties, et rien dans cette migration ne les a mises de côté.
     * Réélargir les CHECK est en revanche nécessaire pour qu'une base rollbackée
     * accepte à nouveau le type si le module devait revenir.
     */
    public function down(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $constraint = self::constraintName($table);
            $values = implode(',', array_map(
                static fn (string $type): string => "'{$type}'",
                [...self::TYPES, 'credit_note'],
            ));

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$values}))"
            );
        }
    }

    /** Le nom historique de la contrainte diffère d'une table à l'autre. */
    private static function constraintName(string $table): string
    {
        return $table === 'sequences' ? 'sequences_doc_type_check' : 'documents_type_check';
    }
};
