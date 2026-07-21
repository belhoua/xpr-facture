<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Exceptions\NoFiscalYearForDate;
use App\Modules\Accounting\Exceptions\NumberingOutsideTransaction;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Sequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Attribution des numéros de documents — séquence continue, sans trou et sans
 * réutilisation (§3).
 *
 * Trois garanties, dans l'ordre où elles comptent :
 *
 * 1. ATOMICITÉ — `SELECT … FOR UPDATE` verrouille la ligne de séquence. Deux
 *    validations simultanées sur le même (société, type, exercice) sont
 *    sérialisées : la seconde attend et obtient le numéro suivant, jamais le
 *    même.
 *
 * 2. PAS DE TROU — le compteur est une ligne de table, pas une SEQUENCE
 *    PostgreSQL. Une sequence est non-transactionnelle : son incrément survit
 *    au rollback et laisserait un numéro consommé pour un document qui n'existe
 *    pas. Ici, si la transaction échoue, le numéro n'a jamais été attribué.
 *
 * 3. AU BON MOMENT — le numéro s'attribue À LA VALIDATION, pas à la création du
 *    brouillon. C'est l'appelant qui le garantit, en appelant ce service dans
 *    la transaction qui fait passer le document à l'état validé.
 *
 * La vérification d'une transaction ouverte est donc une protection de
 * conception : hors transaction, la garantie 1 tombe et deux requêtes
 * concurrentes peuvent lire le même `next_number`.
 */
final class DocumentNumberService
{
    /**
     * Alloue le prochain numéro et consomme le compteur.
     *
     * @param  Carbon  $issuedAt  Date d'émission — elle désigne l'exercice, donc
     *                            la séquence, donc le millésime du numéro.
     *
     * @throws NumberingOutsideTransaction si aucune transaction n'est ouverte
     * @throws NoFiscalYearForDate si aucun exercice ne couvre la date
     */
    public function allocate(DocumentType $type, Carbon $issuedAt): string
    {
        if (DB::transactionLevel() === 0) {
            throw new NumberingOutsideTransaction($type);
        }

        $fiscalYear = FiscalYear::query()->covering($issuedAt)->first();

        if (! $fiscalYear instanceof FiscalYear) {
            throw new NoFiscalYearForDate($issuedAt);
        }

        $sequence = $this->lockSequence($type, $fiscalYear);
        $number = $sequence->next_number;

        // Incrément par expression SQL : la valeur en base fait foi, on ne
        // réécrit pas une valeur lue plus tôt en mémoire.
        Sequence::query()
            ->whereKey($sequence->id)
            ->update(['next_number' => DB::raw('next_number + 1'), 'updated_at' => now()]);

        return $sequence->formatNumber($number, $fiscalYear);
    }

    /**
     * Verrouille la ligne de séquence, en la créant si l'exercice vient de
     * s'ouvrir. `lockForUpdate()` ne verrouille rien quand aucune ligne n'existe
     * encore : deux transactions concurrentes pourraient toutes deux la créer.
     * La contrainte UNIQUE (company_id, document_type, fiscal_year_id) tranche —
     * la perdante reprend la ligne gagnante, cette fois verrouillée.
     */
    private function lockSequence(DocumentType $type, FiscalYear $fiscalYear): Sequence
    {
        $locked = Sequence::query()
            ->where('document_type', $type->value)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->lockForUpdate()
            ->first();

        if ($locked instanceof Sequence) {
            return $locked;
        }

        try {
            Sequence::query()->create([
                'fiscal_year_id' => $fiscalYear->id,
                'document_type' => $type->value,
                'format' => $type->defaultFormat(),
                'next_number' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Une transaction concurrente a créé la ligne entre-temps.
        }

        return Sequence::query()
            ->where('document_type', $type->value)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Numéro qui SERAIT attribué, sans rien consommer — pour un aperçu dans
     * l'interface. Ne jamais persister ce résultat : entre l'aperçu et la
     * validation, une autre validation peut passer devant.
     */
    public function preview(DocumentType $type, Carbon $issuedAt): ?string
    {
        $fiscalYear = FiscalYear::query()->covering($issuedAt)->first();

        if (! $fiscalYear instanceof FiscalYear) {
            return null;
        }

        $sequence = Sequence::query()
            ->where('document_type', $type->value)
            ->where('fiscal_year_id', $fiscalYear->id)
            ->first();

        if (! $sequence instanceof Sequence) {
            // Séquence pas encore ouverte : le premier document de l'exercice.
            $sequence = new Sequence(['format' => $type->defaultFormat()]);

            return $sequence->formatNumber(1, $fiscalYear);
        }

        return $sequence->formatNumber($sequence->next_number, $fiscalYear);
    }
}
