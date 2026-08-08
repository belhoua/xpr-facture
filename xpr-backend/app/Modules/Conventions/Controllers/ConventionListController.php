<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Resources\ConventionResource;
use App\Modules\Conventions\Services\ConventionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ConventionListController
{
    public function __construct(private readonly ConventionService $conventions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->conventions->paginate([
            'search' => $request->string('search')->toString() ?: null,
            // Chaîne vide ⇒ pas de filtre. « all » est un état de l'interface,
            // pas une valeur d'enum : le laisser passer ferait lever
            // ConventionStatus::from().
            'status' => $request->string('status')->toString() ?: null,
            'perPage' => $request->integer('perPage', 25),
        ]);

        return response()->json([
            'data' => ConventionResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
