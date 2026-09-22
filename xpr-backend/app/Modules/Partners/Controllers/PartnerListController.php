<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Resources\PartnerResource;
use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartnerListController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->partners->paginate([
            'type' => $request->string('type')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            // `has` et non `boolean` : sans le paramètre, on ne filtre pas —
            // `boolean()` rendrait false et masquerait les fiches actives.
            'active' => $request->has('active') ? $request->boolean('active') : null,
            'perPage' => $this->resolvePerPage($request),
        ]);

        return response()->json([
            'data' => PartnerResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * `perPage=all` demande l'intégralité du répertoire — pas de découpage à
     * l'écran. `PartnerService::paginate()` la borne quand même à sa limite
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
