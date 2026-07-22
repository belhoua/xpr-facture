<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Requests\ProductUpdateRequest;
use App\Modules\Catalog\Resources\ProductResource;
use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;

/**
 * L'article est résolu dans le service, donc APRÈS le middleware `tenant` :
 * un binding implicite le résoudrait hors scope (cf. RouteBindingScopeTest).
 */
final class ProductUpdateController
{
    public function __construct(private readonly ProductService $products) {}

    public function __invoke(ProductUpdateRequest $request, string $product): JsonResponse
    {
        $model = $this->products->findForCompany($product);

        return response()->json(
            (new ProductResource($this->products->update($model, $request->validated())))->resolve(),
        );
    }
}
