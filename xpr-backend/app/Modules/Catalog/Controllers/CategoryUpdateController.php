<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Requests\CategoryUpdateRequest;
use App\Modules\Catalog\Resources\CategoryResource;
use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;

/**
 * La catégorie est résolue dans le service, donc APRÈS le middleware `tenant` :
 * un binding implicite la résoudrait hors scope (cf. RouteBindingScopeTest).
 */
final class CategoryUpdateController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function __invoke(CategoryUpdateRequest $request, string $category): JsonResponse
    {
        $model = $this->categories->findForCompany($category);

        return response()->json(
            (new CategoryResource($this->categories->update($model, $request->validated())))->resolve(),
        );
    }
}
