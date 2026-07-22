<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;

final class ProductShowController
{
    public function __construct(private readonly ProductService $products) {}

    public function __invoke(string $product): JsonResponse
    {
        return response()->json(
            (new ProductResource($this->products->findForCompany($product)))->resolve(),
        );
    }
}
