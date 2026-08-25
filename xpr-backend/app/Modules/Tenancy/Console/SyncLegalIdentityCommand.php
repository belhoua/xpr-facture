<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Console;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Aligne les mentions légales d'une société sur les valeurs de référence de
 * l'exploitant.
 *
 * ── Pourquoi une commande de plus ──────────────────────────────────────────
 *
 * Ces mêmes valeurs sont déjà posées à DEUX endroits :
 *  - la migration `2026_08_15_000001_fill_bcat_legal_identity`, pour les bases
 *    déjà peuplées au moment du correctif ;
 *  - `AdminSeeder::fillLegalIdentity`, pour les bases où la société est créée
 *    après que les migrations ont toutes tourné.
 *
 * Les deux ne comblent QUE LES VIDES, délibérément : une valeur saisie depuis
 * l'écran des paramètres est le fait d'un utilisateur qui savait ce qu'il
 * écrivait, et `db:seed` est rejoué bien plus souvent qu'on ne le croit.
 *
 * Il manquait donc l'outil du cas inverse : une mention DÉJÀ RENSEIGNÉE mais
 * FAUSSE — une coquille dans l'ICE, un RIB d'un ancien compte, un numéro de
 * téléphone périmé. Aucun des deux mécanismes ne la corrige, et c'est le seul
 * cas où l'on a réellement besoin d'écrire en production.
 *
 * Une commande plutôt qu'un `tinker --execute` : celui-ci ne laisse aucune
 * trace, n'est pas relu en revue, n'est pas rejouable à l'identique et
 * s'exécute sans montrer ce qu'il écrase. Sur des mentions fiscales portées par
 * chaque facture, ces quatre points ne sont pas des détails.
 *
 * ── Ce qu'elle ne fait pas ─────────────────────────────────────────────────
 *
 * Elle n'écrit PAS dans `audit_logs`. La table existe (P0-08) mais personne ne
 * l'alimente encore : le module Audit n'est pas écrit. En faire ici son premier
 * écrivain poserait la convention du journal depuis une commande de
 * maintenance, ce qui est exactement l'improvisation que le dépôt refuse
 * ailleurs. La trace est donc le comparatif imprimé et la confirmation
 * explicite — à remplacer par un vrai événement d'audit quand le module
 * arrivera.
 *
 * ── Usage ─────────────────────────────────────────────────────────────────
 *
 *   php artisan xpr:sync-legal-identity --dry-run   # comparatif seul
 *   php artisan xpr:sync-legal-identity             # écrit après confirmation
 *   php artisan xpr:sync-legal-identity --force     # shell non interactif
 *
 * La fonction serverless n'expose pas artisan (cf.
 * `docs/architecture/06-deploiement-serverless.md` §7) : comme les migrations
 * et `xpr:create-admin`, elle se lance depuis un poste local pointé sur la base
 * distante.
 */
final class SyncLegalIdentityCommand extends Command
{
    protected $signature = 'xpr:sync-legal-identity
        {--company=BCAT : Raison sociale ciblée (correspondance exacte, insensible à la casse)}
        {--force : N\'invite pas à confirmer — pour un shell non interactif}
        {--dry-run : Affiche le comparatif et n\'écrit rien}';

    protected $description = 'Aligne les mentions légales d\'une société sur les valeurs de référence';

    /**
     * Mentions de référence de l'exploitant, telles qu'elles figurent sur son
     * papier à en-tête.
     *
     * `address` ne porte PAS la ville : elle vit dans `city` (« OUJDA 60000 »,
     * code postal compris, faute de colonne dédiée). Les fusionner ferait
     * imprimer la ville deux fois au pied de chaque document — `LegalFooter`
     * recompose la ligne « Adresse : {address}, {city} ».
     *
     * `rc_city` est conservée bien que le pied ne l'imprime plus : le tribunal
     * de rattachement reste une mention attendue (CLAUDE.md §3), et la donnée
     * doit exister le jour où on la remet sur le papier.
     *
     * @var array<string, string>
     */
    private const IDENTITY = [
        'ice' => '002091111000017',
        'if_number' => '26066474',
        'cnss' => '1144864',
        'rc_number' => '32577',
        'rc_city' => 'Oujda',
        'patente' => '10100485',
        'address' => '8 BD Moulay Ahmed Lagrari 6ème Étage App N°25',
        'city' => 'OUJDA 60000',
        'phone' => '0536686883 / 0661940997',
        'email' => 'bcatcontrol@gmail.com',
        'bank_rib' => '011640000032210000180410',
    ];

    public function handle(): int
    {
        $target = $this->stringOption('company') ?? 'BCAT';

        $company = $this->resolveCompany($target);

        if (! $company instanceof Company) {
            return self::FAILURE;
        }

        $this->line("Société : « {$company->legal_name} » ({$company->id})");
        $this->newLine();

        $changes = $this->render($company);

        if ($changes === []) {
            $this->info('Rien à faire : les mentions sont déjà alignées.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            $this->comment(count($changes).' champ(s) à écrire. Rien n\'a été modifié (--dry-run).');

            return self::SUCCESS;
        }

        if (! $this->confirmWrite($company, $changes)) {
            $this->comment('Abandon : rien n\'a été modifié.');

            return self::SUCCESS;
        }

        // UPDATE explicite par le query builder, et non `fill()->save()`.
        //
        // Constat du 2026-08-25 sur la base de production : la version à
        // `fill()->save()` a rendu la main sans erreur, la commande a annoncé
        // « 11 champs mis à jour », et AUCUNE ligne n'avait bougé — `updated_at`
        // était resté à sa valeur de création. La cause n'est pas établie ; ce
        // qui l'est, c'est que `save()` n'émet aucune requête dès que rien n'est
        // marqué modifié, et qu'il ne le signale pas.
        //
        // L'UPDATE explicite retire cet intermédiaire et rend le nombre de
        // lignes touchées observable — c'est lui, et la relecture qui suit, qui
        // autorisent à parler de succès.
        //
        // La transaction n'est pas là pour l'atomicité d'un UPDATE unique, mais
        // pour ne pas laisser une écriture sur des mentions fiscales échapper au
        // comportement transactionnel du reste du dépôt.
        $affected = DB::transaction(static fn (): int => Company::query()
            ->whereKey($company->getKey())
            ->update($changes));

        if ($affected !== 1) {
            $this->error("L'UPDATE a touché {$affected} ligne(s) au lieu d'une. Rien n'est garanti — vérifiez avant de relancer.");

            return self::FAILURE;
        }

        return $this->verify($company, $changes);
    }

    /**
     * Relit la ligne depuis la base et compare champ à champ.
     *
     * Un `save()` qui ne lève pas n'est pas la preuve d'une écriture : Eloquent
     * n'émet aucune requête quand rien n'est marqué modifié, et le nombre de
     * lignes affectées ne dit rien de ce qui a réellement été posé dans les
     * colonnes. Sur des mentions imprimées au pied de chaque facture, annoncer
     * un succès sans l'avoir constaté est le pire des deux mondes : la
     * correction paraît faite et ne l'est pas.
     *
     * `fresh()` et non l'instance en mémoire : celle-ci porte ce qu'on voulait
     * écrire, pas ce que la base a retenu.
     *
     * @param  array<string, string>  $changes
     */
    private function verify(Company $company, array $changes): int
    {
        $fresh = $company->fresh();

        if (! $fresh instanceof Company) {
            $this->error('La société a disparu entre l\'écriture et la relecture.');

            return self::FAILURE;
        }

        $divergent = [];

        foreach ($changes as $column => $expected) {
            if ($fresh->getAttribute($column) !== $expected) {
                $divergent[] = $column;
            }
        }

        if ($divergent !== []) {
            $this->error('Écriture NON confirmée sur : '.implode(', ', $divergent));

            return self::FAILURE;
        }

        $this->info(count($changes).' champ(s) mis à jour, relus et confirmés en base.');

        return self::SUCCESS;
    }

    /**
     * Société ciblée, ou null avec le motif déjà affiché.
     *
     * Correspondance EXACTE sur la raison sociale, pas un `LIKE` : « BCAT »
     * doit désigner BCAT et rien d'autre. Une base de production porte
     * plusieurs sociétés, et écrire l'ICE de l'exploitant sur celle d'un tiers
     * fabriquerait un faux document à chaque facture qu'elle émettrait ensuite.
     *
     * Plusieurs correspondances : on refuse au lieu de prendre la première.
     * Deviner laquelle des deux mérite l'ICE n'est pas au programme d'une
     * commande de maintenance.
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
            $this->line('Sociétés connues : '.$this->knownCompanies());

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

    /** Raisons sociales en base, pour rendre l'échec actionnable. */
    private function knownCompanies(): string
    {
        /** @var list<string> $names */
        $names = Company::query()->orderBy('legal_name')->pluck('legal_name')->all();

        return $names === [] ? '(aucune)' : implode(', ', $names);
    }

    /**
     * Imprime le comparatif et renvoie les seuls champs à écrire.
     *
     * Trois statuts, et le troisième est le seul qui demande de l'attention :
     * un champ VIDE se comble sans risque, un champ DIVERGENT écrase ce que
     * quelqu'un a saisi.
     *
     * @return array<string, string>
     */
    private function render(Company $company): array
    {
        $rows = [];
        $changes = [];

        foreach (self::IDENTITY as $column => $expected) {
            $current = $company->getAttribute($column);
            $current = is_string($current) ? $current : null;

            if ($current === $expected) {
                $rows[] = ['=', $column, $current, ''];

                continue;
            }

            $changes[$column] = $expected;

            $rows[] = in_array($current, [null, ''], strict: true)
                ? ['+', $column, '—', $expected]
                : ['!', $column, $current, $expected];
        }

        $this->table(['', 'Champ', 'Actuel', 'Attendu'], $rows);
        $this->line('=  identique    +  à compléter    !  à corriger (écrase une valeur saisie)');
        $this->newLine();

        return $changes;
    }

    /**
     * Confirmation, plus insistante quand des valeurs saisies sont écrasées.
     *
     * `--force` existe parce qu'un shell non interactif (conteneur, CI,
     * `docker compose exec -T`) ne peut répondre à aucune invite. Il ne rend
     * pas l'opération plus sûre : il fait seulement l'aveu qu'on l'assume.
     *
     * @param  array<string, string>  $changes
     */
    private function confirmWrite(Company $company, array $changes): bool
    {
        $overwritten = array_keys(array_filter(
            $changes,
            static fn (string $column): bool => ! in_array(
                $company->getAttribute($column), [null, ''], strict: true,
            ),
            ARRAY_FILTER_USE_KEY,
        ));

        if ($overwritten !== []) {
            $this->warn('Valeurs déjà saisies qui seront ÉCRASÉES : '.implode(', ', $overwritten));
        }

        if ($this->option('force') === true) {
            return true;
        }

        return $this->confirm(
            "Écrire ces mentions sur « {$company->legal_name} » ?",
            default: false,
        );
    }

    /** Option de chaîne, ou null : `option()` renvoie `mixed` et PHPStan 8 le sait. */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
