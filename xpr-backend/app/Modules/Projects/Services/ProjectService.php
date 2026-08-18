<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lectures du module Avancement de projet.
 *
 * Toutes les résolutions passent par ici, et TOUTES sous le scope tenant : un
 * identifiant venu de l'URL ne doit jamais être résolu autrement (§5). Les
 * contrôleurs reçoivent donc des `string` et appellent ce service — c'est ce
 * que verrouille `tests/Feature/Tenancy/RouteBindingScopeTest.php`.
 */
final class ProjectService
{
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
     * @param  array{search?: ?string, status?: ?string, partnerId?: ?string, perPage?: ?int, page?: ?int}  $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Project::query()->with(['partner', 'service', 'deliverables']);

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

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $filters['page'] ?? null)
            ->withQueryString();
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
