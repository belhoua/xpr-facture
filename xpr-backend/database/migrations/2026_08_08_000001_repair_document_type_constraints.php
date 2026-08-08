<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Réparation des contraintes CHECK de type de document sur une base dont l'état
 * a DÉRIVÉ du dépôt.
 *
 * ── Le défaut observé ───────────────────────────────────────────────────────
 *
 * En production (Neon), l'inscription échoue en `SQLSTATE[23514]` sur
 * `sequences`. Le chemin est exactement celui-ci :
 *
 *   RegisterController → CompanyProvisioning → CompanyAccountingProvisioning
 *     → boucle sur DocumentType::provisionedAtSignup()
 *       = [invoice, quote, credit_note, SITUATION]
 *     → INSERT INTO sequences (document_type = 'situation')
 *
 * `situation` est né le 2026-08-05. La migration de ce jour
 * (`add_situations_to_documents`) élargit les deux contraintes en conséquence.
 * Si elle n'a pas été jouée — ou si elle l'a été partiellement — la base garde
 * la liste d'origine à 8 types, et l'INSERT ci-dessus est refusé. Toute
 * inscription est alors impossible, puisque le provisioning est dans la
 * transaction du compte.
 *
 * ── Pourquoi une migration de plus, et pas seulement `migrate` ──────────────
 *
 * Si la base n'a réellement que du retard, `php artisan migrate` suffit et
 * celle-ci ne fait rien de plus. Elle existe pour le cas où la table
 * `migrations` déclare `add_situations_to_documents` comme jouée alors que les
 * contraintes ne l'ont pas suivi : Laravel ne rejouera jamais cette ligne, et
 * la base resterait fautive indéfiniment. Étant IDEMPOTENTE, cette migration
 * est sans effet sur une base saine et corrige l'autre.
 *
 * ── La liste de types, et ce qu'elle n'est pas ─────────────────────────────
 *
 * Miroir exact de `Accounting\Enums\DocumentType` : les 9 cas, ni plus ni
 * moins. Deux écarts ont été écartés délibérément :
 *
 *  - **on ne retire aucun type.** Restreindre la liste à ceux qu'on utilise
 *    aujourd'hui (devis, facture, avoir, situation) ferait échouer l'ALTER
 *    lui-même si une ligne `proforma` ou `delivery_note` existe — PostgreSQL
 *    valide les lignes présentes en posant la contrainte — et bloquerait ces
 *    types le jour où leur module arrive ;
 *  - **`convention` n'y figure pas.** Une convention n'est pas un document
 *    commercial : elle a sa table (`conventions`), et son n° de dossier est
 *    délivré par l'organisme instructeur, jamais tiré de `sequences`. L'ajouter
 *    ici décrirait une numérotation qui n'existe pas.
 *
 * La nouvelle liste étant un SUR-ENSEMBLE de l'ancienne, aucune ligne existante
 * ne peut la violer : la pose est sûre sur une base peuplée.
 */
return new class extends Migration
{
    /** Miroir de `Accounting\Enums\DocumentType`. */
    private const TYPES = [
        'invoice', 'quote', 'proforma', 'purchase_order',
        'delivery_note', 'shipping_slip', 'credit_note',
        'purchase_invoice', 'situation',
    ];

    /** @var array<string, string> table => colonne discriminante */
    private const TARGETS = [
        // Celle qui casse l'inscription.
        'sequences' => 'document_type',
        // Même origine, même correctif : les deux ont été élargies par la même
        // migration le 2026-08-05. Une base qui a manqué l'une a manqué
        // l'autre, et la seconde ferait échouer la création d'une situation
        // juste après que la première a laissé passer sa séquence.
        'documents' => 'type',
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $column) {
            $constraint = self::constraintName($table);

            // IF EXISTS : c'est ce qui rend l'opération rejouable. Sans lui, une
            // base déjà réparée — ou dont la contrainte porte un autre nom —
            // ferait échouer la migration et laisserait la suivante en attente.
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");

            $values = implode(',', array_map(
                static fn (string $type): string => "'{$type}'",
                self::TYPES,
            ));

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$values}))"
            );
        }
    }

    /**
     * Vide, comme les autres migrations de resynchronisation du dépôt
     * (`sync_roles_and_permissions`, `rename_invoice_permissions_to_documents`).
     *
     * Rétablir la liste à 8 types remettrait la base dans l'état qui empêche
     * toute inscription, et échouerait de toute façon si des situations ont
     * été émises entre-temps. Cette migration ne crée rien qu'un `down` doive
     * défaire : elle réaligne.
     */
    public function down(): void {}

    /** Le nom historique de la contrainte diffère d'une table à l'autre. */
    private static function constraintName(string $table): string
    {
        return $table === 'sequences' ? 'sequences_doc_type_check' : 'documents_type_check';
    }
};
