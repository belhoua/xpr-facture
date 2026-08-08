<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute l'état `in_progress` — « en cours ».
 *
 * Demandé pour les SITUATIONS : entre « non payé » et « partiel », il manquait
 * l'état d'un décompte ouvert dont on ne réclame pas encore le règlement
 * (chantier en cours, prestation non achevée).
 *
 * Pourquoi un état NOUVEAU plutôt que `draft`, déjà disponible :
 *
 * `draft` n'est pas un état d'avancement, c'est un état de RÉDACTION. Tout le
 * moteur s'appuie dessus pour dire « ce document n'a pas de numéro » —
 * `settlementStatus()` le renvoie quand `isIssued()` est faux, `isEditable()`
 * en dérive, et l'émission le consomme. Or une situation est numérotée dès sa
 * création : la marquer `draft` la ferait passer pour non émise partout où le
 * code interroge son état, à commencer par les écrans qui distinguent un
 * brouillon d'une pièce numérotée.
 *
 * `in_progress` reste donc un état d'un document ÉMIS, au même titre que
 * `partial` : il dit où en est l'affaire, pas où en est la saisie.
 */
return new class extends Migration
{
    /** États d'un document, `in_progress` compris. Miroir de `DocumentStatus`. */
    private const STATUSES = [
        'draft', 'sent', 'accepted', 'refused', 'converted',
        'partial', 'paid', 'overdue', 'cancelled', 'in_progress',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_status_check');
        DB::statement($this->statusCheck(self::STATUSES));
    }

    public function down(): void
    {
        // Les documents portant l'état retiré violeraient la contrainte
        // reconstruite. On les ramène à `sent` — l'état émis le plus neutre —
        // plutôt que de laisser la migration échouer à mi-parcours sur une base
        // qui en contient. Aucune perte comptable : `in_progress` ne porte
        // aucun montant qui lui soit propre.
        DB::table('documents')->where('status', 'in_progress')->update(['status' => 'sent']);

        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_status_check');
        DB::statement($this->statusCheck(
            array_values(array_diff(self::STATUSES, ['in_progress'])),
        ));
    }

    /**
     * Contrainte CHECK d'énumération. Les valeurs viennent d'une constante de
     * classe, jamais d'une entrée externe — aucune interpolation à risque.
     *
     * @param  list<string>  $values
     */
    private function statusCheck(array $values): string
    {
        $list = implode(',', array_map(static fn (string $v): string => "'{$v}'", $values));

        return "ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ({$list}))";
    }
};
