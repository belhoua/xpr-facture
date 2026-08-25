<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lectures du module Avancement de projet.
 *
 * Toutes les résolutions passent par ici, et TOUTES sous le scope tenant : un
 * identifiant venu de l'URL ne doit jamais être résolu autrement (§5). Les
 * contrôleurs reçoivent donc des `string` et appellent ce service — c'est ce
 * que verrouille `tests/Feature/Tenancy/RouteBindingScopeTest.php`.
 *
 * @phpstan-type Filters array{
 *     search?: ?string,
 *     status?: ?string,
 *     partnerId?: ?string,
 *     perPage?: ?int,
 *     page?: ?int,
 * }
 */
final class ProjectService
{
    /**
     * Une fiche est INCOMPLÈTE, en SQL.
     *
     * Deux manques, et deux seulement : pas de description, ou aucun livrable
     * annoncé. Ce sont les champs qu'un projet ouvert à la hâte — depuis un
     * intitulé de devis, par exemple — laisse systématiquement derrière lui,
     * et ceux qu'il faut reprendre pour que la fiche serve à quelque chose.
     *
     * Le SERVICE n'entre PAS dans le compte, bien qu'il soit lui aussi
     * facultatif : le référentiel des services naît vide (cf. le schéma de
     * saisie), et l'y inclure marquerait « à compléter » l'intégralité des
     * projets d'une société qui ne s'en sert pas — un indicateur qui ne
     * redescend jamais à zéro n'indique plus rien.
     *
     * `deleted_at IS NULL` est écrit à la main : la sous-requête est du SQL
     * brut, le global scope de soft delete de `Deliverable` ne s'y applique
     * pas, et un livrable retiré continuerait de « compléter » la fiche.
     *
     * Le filtre `company_id` est volontairement absent de la sous-requête :
     * elle est corrélée à `projects.id`, une clé primaire UUID déjà restreinte
     * à la société par le scope de la requête englobante — et la RLS couvre
     * `deliverables` de son côté (§5).
     *
     * Miroir exact de `isIncomplete()` côté frontend
     * (`features/projects/schemas/project.ts`). Les deux DOIVENT dire la même
     * chose : le compte de la carte et le bandeau de la ligne se
     * contrediraient sinon.
     */
    private const INCOMPLETE_SQL = <<<'SQL'
        (
            projects.description IS NULL
            OR btrim(projects.description) = ''
            OR NOT EXISTS (
                SELECT 1 FROM deliverables d
                WHERE d.project_id = projects.id AND d.deleted_at IS NULL
            )
        )
        SQL;

    /**
     * Comptes de l'écran : total, en cours, à compléter, terminés.
     *
     * Un endpoint dédié plutôt qu'un décompte des lignes reçues : la liste est
     * paginée, compter la page afficherait « 25 projets » sur un portefeuille
     * qui en compte quarante — et faux sans le dire.
     *
     * Aucun MONTANT n'y figure, et ce n'est pas un oubli : un projet n'est pas
     * une pièce commerciale. Ce qu'il a rapporté se lit sur les documents qui
     * lui sont rattachés (`documents.project_id`), où les règlements sont
     * connus ; l'afficher ici obligerait à additionner des devis et des
     * factures, donc à annoncer un chiffre d'affaires qui n'en est pas un.
     *
     * @param  Filters  $filters
     * @return array{count: int, inProgress: int, incomplete: int, completed: int}
     */
    public function summary(array $filters): array
    {
        /** @var object{aggregate_count: int|string, in_progress: int|string, incomplete: int|string, completed: int|string} $row */
        $row = $this->filtered($filters)
            ->toBase()
            ->selectRaw(sprintf(
                'COUNT(*) AS aggregate_count'
                .', COUNT(*) FILTER (WHERE status = ?) AS in_progress'
                .', COUNT(*) FILTER (WHERE status = ?) AS completed'
                .', COUNT(*) FILTER (WHERE %s) AS incomplete',
                self::INCOMPLETE_SQL,
            ), [ProjectStatus::InProgress->value, ProjectStatus::Completed->value])
            ->first();

        return [
            'count' => (int) $row->aggregate_count,
            'inProgress' => (int) $row->in_progress,
            'incomplete' => (int) $row->incomplete,
            'completed' => (int) $row->completed,
        ];
    }

    /**
     * Liste paginée, du PLUS RÉCENT au plus ancien.
     *
     * `created_at` et non une date métier : un projet n'a pas de date
     * d'ouverture saisie, et l'ordre attendu sur cet écran est celui de la
     * saisie. `id` en second critère départage deux projets créés dans la même
     * milliseconde — les UUID v7 étant ordonnés dans le temps (§7), c'est un
     * ordre stable et non arbitraire, ce qui évite qu'une même page change de
     * contenu d'un rechargement à l'autre.
     *
     * Le client et les livrables sont chargés d'emblée : la liste affiche le
     * nom du client et le nombre de remises sur chaque ligne, les lire ligne
     * par ligne ferait 50 requêtes par page.
     *
     * @param  Filters  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $this->filtered($filters)
            ->with(['partner', 'service', 'deliverables'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $filters['page'] ?? null)
            ->withQueryString();
    }

    /**
     * Socle de filtrage partagé par la liste et les comptes.
     *
     * Factorisé pour une raison de justesse et non d'économie : si les deux
     * appliquaient leurs filtres séparément, une divergence ferait afficher des
     * cartes qui ne décriraient plus les lignes en dessous.
     *
     * @param  Filters  $filters
     * @return Builder<Project>
     */
    private function filtered(array $filters): Builder
    {
        $query = Project::query();

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        // `ProjectStatus::from()` et non `tryFrom()` : un statut inconnu est une
        // erreur d'appel, pas un filtre à ignorer silencieusement. Le
        // contrôleur a déjà transformé « all » — un état de l'interface — en
        // absence de filtre.
        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', ProjectStatus::from($status)->value);
        }

        if (($partnerId = $filters['partnerId'] ?? null) !== null && $partnerId !== '') {
            $query->where('partner_id', $partnerId);
        }

        return $query;
    }

    /**
     * Résout un projet de la SOCIÉTÉ ACTIVE, client et livrables compris.
     *
     * @throws ModelNotFoundException 404 et non 403 : l'existence même d'un
     *                                projet d'une autre société ne doit pas
     *                                fuiter.
     */
    public function findForCompany(string $id): Project
    {
        $project = Project::query()->with(['partner', 'service', 'deliverables'])->find($id);

        if (! $project instanceof Project) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$id]);
        }

        return $project;
    }

    /** @throws ModelNotFoundException */
    public function findDeliverableForCompany(string $id): Deliverable
    {
        $deliverable = Deliverable::query()->find($id);

        if (! $deliverable instanceof Deliverable) {
            throw (new ModelNotFoundException)->setModel(Deliverable::class, [$id]);
        }

        return $deliverable;
    }
}
