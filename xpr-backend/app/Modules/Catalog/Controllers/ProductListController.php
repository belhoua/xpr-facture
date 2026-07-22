<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductListController
{
    public function __construct(private readonly ProductService $products) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->products->paginate([
            'search' => $request->string('search')->toString() ?: null,
            'type' => $request->string('type')->toString() ?: null,
            'categoryId' => $request->string('categoryId')->toString() ?: null,
            'active' => $request->has('active') ? $request->boolean('active') : null,
            'perPage' => $request->integer('perPage', 25),
        ]);

        return response()->json([
            'data' => ProductResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
