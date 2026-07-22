<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class CategoryArchiveController
{
    public function __construct(private readonly CategoryService $categories) {}

    public function __invoke(string $category): JsonResponse
    {
        $this->categories->archive($this->categories->findForCompany($category));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
