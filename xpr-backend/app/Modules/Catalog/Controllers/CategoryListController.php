<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Resources\CategoryResource;
use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryListController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->categories->paginate([
            'search' => $request->string('search')->toString() ?: null,
            // `has` et non `boolean` : sans le paramètre on ne filtre pas —
            // `boolean()` rendrait false et masquerait les fiches actives.
            'active' => $request->has('active') ? $request->boolean('active') : null,
            'perPage' => $request->integer('perPage', 50),
        ]);

        return response()->json([
            'data' => CategoryResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
