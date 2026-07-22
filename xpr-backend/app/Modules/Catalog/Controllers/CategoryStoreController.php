<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Requests\CategoryStoreRequest;
use App\Modules\Catalog\Resources\CategoryResource;
use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CategoryStoreController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function __invoke(CategoryStoreRequest $request): JsonResponse
    {
        return response()->json(
            (new CategoryResource($this->categories->create($request->validated())))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
