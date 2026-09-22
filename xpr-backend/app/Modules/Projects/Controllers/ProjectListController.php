<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste paginée des projets, du plus RÉCENT au plus ancien.
 *
 * Filtres : client et statut, les deux du bandeau de l'écran.
 */
final class ProjectListController
{
    public function __construct(private readonly ProjectService $projects) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->projects->paginate([
            'search' => $request->string('search')->toString() ?: null,
            // Chaîne vide ⇒ pas de filtre. « all » est un état de l'interface,
            // pas une valeur d'enum : le laisser passer ferait lever
            // ProjectStatus::from().
            'status' => $request->string('status')->toString() ?: null,
            // `partnerId` et non `client_id` : c'est le nom que porte la
            // colonne et que parlent déjà les documents et les conventions.
            'partnerId' => $request->string('partnerId')->toString() ?: null,
            'perPage' => $this->resolvePerPage($request),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json([
            'data' => ProjectResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * `perPage=all` demande l'intégralité des chantiers — pas de découpage à
     * l'écran. `ProjectService::paginate()` la borne quand même à sa limite
     * haute (§16 CLAUDE.md, VPS 1 vCPU) : jamais une valeur réellement illimitée.
     */
    private function resolvePerPage(Request $request): int
    {
        $raw = $request->query('perPage');

        return is_string($raw) && mb_strtolower($raw) === 'all'
            ? PHP_INT_MAX
            : $request->integer('perPage', 25);
    }
}
