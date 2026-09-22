<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Sequence;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recale chaque compteur de `sequences` sur le plus haut numéro RÉELLEMENT
 * présent en base, pour un type de document et un exercice donnés.
 *
 * ── Pourquoi une commande de plus ──────────────────────────────────────────
 *
 * `DocumentNumberService::syncFromManualNumber()` (2026-09-22) empêche
 * désormais l'automatique de heurter un numéro saisi à la main ou posé par
 * une renumérotation — mais seulement pour les numéros posés APRÈS son
 * déploiement. Elle ne corrige rien de ce qui a déjà divergé : une base où le
 * compteur d'un type est resté en arrière d'un numéro déjà attribué a besoin
 * d'un rattrapage explicite, une fois, pas d'une garde qui ne s'applique qu'à
 * l'avenir.
 *
 * Une commande plutôt qu'un `tinker --execute`, pour les mêmes raisons que
 * `xpr:sync-legal-identity` : traçable, relue en revue, rejouable à
 * l'identique, et qui montre ce qu'elle s'apprête à écraser avant de le faire.
 *
 * ── Ce qu'elle NE fait PAS ─────────────────────────────────────────────────
 *
 *  - elle ne comble AUCUN trou. Un compteur recalé au-delà d'un numéro isolé
 *    laisse les rangs intermédiaires inutilisés — exactement le coût déjà
 *    assumé par `DocumentType::numbersOnCreate()` et `allowsNumberEdit()` ;
 *  - elle ne fait jamais RECULER un compteur. Le rattrapage n'est qu'une
 *    remontée vers le MAX constaté, jamais une réécriture arbitraire ;
 *  - elle ne renumérote AUCUN document existant. Rattacher un compteur à la
 *    réalité et réécrire le numéro déjà imprimé d'une pièce sont deux actes de
 *    poids différent ; celui-ci ne fait que le premier.
 *
 * Usage :
 *
 *   php artisan xpr:resync-sequences --dry-run   # comparatif seul
 *   php artisan xpr:resync-sequences             # écrit après confirmation
 *   php artisan xpr:resync-sequences --force     # shell non interactif
 */
final class ResyncSequencesCommand extends Command
{
    protected $signature = 'xpr:resync-sequences
        {--company=BCAT : Raison sociale ciblée (correspondance exacte, insensible à la casse)}
        {--force : N\'invite pas à confirmer — pour un shell non interactif}
        {--dry-run : Affiche le comparatif et n\'écrit rien}';

    protected $description = 'Recale les compteurs de sequences sur le plus haut numéro réellement attribué en base';

    public function handle(): int
    {
        $company = $this->resolveCompany($this->stringOption('company') ?? 'BCAT');

        if (! $company instanceof Company) {
            return self::FAILURE;
        }

        $this->line("Société : « {$company->legal_name} » ({$company->id})");
        $this->newLine();

        /** @var array<string, int> $targets */
        $targets = [];
        $rows = $this->render($company, $targets);

        $this->table(['', 'Type', 'Exercice', 'Compteur actuel', 'Rang max en base', 'Compteur cible'], $rows);
        $this->line('=  déjà couvert    !  en retard sur un numéro déjà attribué');
        $this->newLine();

        if ($targets === []) {
            $this->info('Rien à recaler : chaque compteur couvre déjà le plus haut numéro en base.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            $this->comment(count($targets).' séquence(s) à recaler. Rien n\'a été modifié (--dry-run).');

            return self::SUCCESS;
        }

        if ($this->option('force') !== true
            && ! $this->confirm('Recaler '.count($targets)." compteur(s) de « {$company->legal_name} » ?", default: false)
        ) {
            $this->comment('Abandon : rien n\'a été modifié.');

            return self::SUCCESS;
        }

        // UPDATE explicite par le query builder plutôt que `fill()->save()` :
        // cf. le constat de production documenté dans
        // `SyncLegalIdentityCommand` — un `save()` qui ne lève pas n'est pas la
        // preuve d'une écriture.
        $affected = DB::transaction(static function () use ($targets): int {
            $count = 0;

            foreach ($targets as $sequenceId => $target) {
                $count += Sequence::query()
                    ->whereKey($sequenceId)
                    ->update(['next_number' => $target, 'updated_at' => now()]);
            }

            return $count;
        });

        if ($affected !== count($targets)) {
            $this->error("L'UPDATE a touché {$affected} ligne(s) au lieu de ".count($targets).'. Vérifiez avant de relancer.');

            return self::FAILURE;
        }

        return $this->verify($targets);
    }

    /**
     * Imprime le comparatif et remplit `$targets` (id de séquence → compteur
     * cible) pour chaque séquence qui prend réellement du retard.
     *
     * @param  array<string, int>  $targets
     * @return list<array{string, string, string, int, int|string, int}>
     */
    private function render(Company $company, array &$targets): array
    {
        $sequences = Sequence::query()
            ->where('company_id', $company->id)
            ->with('fiscalYear')
            ->orderBy('document_type')
            ->get();

        $rows = [];

        foreach ($sequences as $sequence) {
            $fiscalYear = $sequence->fiscalYear;

            if (! $fiscalYear instanceof FiscalYear) {
                continue;
            }

            $maxRank = $this->maxRank($company->id, $sequence, $fiscalYear);
            $target = $maxRank === null ? $sequence->next_number : max($sequence->next_number, $maxRank + 1);

            if ($target > $sequence->next_number) {
                $targets[$sequence->id] = $target;
            }

            $rows[] = [
                $target > $sequence->next_number ? '!' : '=',
                $sequence->document_type->value,
                $fiscalYear->label,
                $sequence->next_number,
                $maxRank ?? '—',
                $target,
            ];
        }

        return $rows;
    }

    /**
     * Plus haut rang trouvé parmi les documents DÉJÀ numérotés de ce type,
     * pièces supprimées comprises — un numéro supprimé reste consommé (§3).
     *
     * `DB::table()` et non le modèle Eloquent `Document` : celui-ci porte le
     * global scope tenant (qui exigerait un `TenantContext` armé, absent
     * d'une commande console) et le soft delete, qui masquerait précisément
     * les numéros qu'il faut compter ici.
     */
    private function maxRank(string $companyId, Sequence $sequence, FiscalYear $fiscalYear): ?int
    {
        $numbers = DB::table('documents')
            ->where('company_id', $companyId)
            ->where('type', $sequence->document_type->value)
            ->whereNotNull('number')
            ->pluck('number');

        $max = null;

        foreach ($numbers as $number) {
            $rank = $sequence->parseNumber((string) $number, $fiscalYear);

            if ($rank !== null && ($max === null || $rank > $max)) {
                $max = $rank;
            }
        }

        return $max;
    }

    /**
     * Relit chaque séquence depuis la base et compare au compteur attendu —
     * même précaution que `SyncLegalIdentityCommand::verify()`.
     *
     * @param  array<string, int>  $targets
     */
    private function verify(array $targets): int
    {
        $divergent = [];

        foreach ($targets as $sequenceId => $expected) {
            $fresh = Sequence::query()->find($sequenceId);

            if (! $fresh instanceof Sequence || $fresh->next_number !== $expected) {
                $divergent[] = $sequenceId;
            }
        }

        if ($divergent !== []) {
            $this->error('Écriture NON confirmée sur : '.implode(', ', $divergent));

            return self::FAILURE;
        }

        $this->info(count($targets).' compteur(s) recalé(s), relu(s) et confirmé(s) en base.');

        return self::SUCCESS;
    }

    /**
     * Société ciblée, ou null avec le motif déjà affiché — correspondance
     * exacte, même règle que `SyncLegalIdentityCommand::resolveCompany()` et
     * pour la même raison : deviner sur plusieurs sociétés n'est pas au
     * programme d'une commande de maintenance.
     */
    private function resolveCompany(string $legalName): ?Company
    {
        /** @var list<Company> $matches */
        $matches = Company::query()
            ->whereRaw('lower(legal_name) = ?', [mb_strtolower($legalName)])
            ->get()
            ->all();

        if ($matches === []) {
            $this->error("Aucune société nommée « {$legalName} ».");

            return null;
        }

        if (count($matches) > 1) {
            $this->error(count($matches)." sociétés portent le nom « {$legalName} » — levez l'ambiguïté avant.");

            foreach ($matches as $match) {
                $this->line("  {$match->id}");
            }

            return null;
        }

        return $matches[0];
    }

    /** Option de chaîne, ou null : `option()` renvoie `mixed` et PHPStan 8 le sait. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
