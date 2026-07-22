<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Requests\ProductStoreRequest;
use App\Modules\Catalog\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProductStoreController
{
    public function __construct(private readonly ProductService $products) {}

    public function __invoke(ProductStoreRequest $request): JsonResponse
    {
        return response()->json(
            (new ProductResource($this->products->create($request->validated())))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
