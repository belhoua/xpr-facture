<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Controllers;

use App\Modules\Catalog\Services\ProductService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProductArchiveController
{
    public function __construct(private readonly ProductService $products) {}

    public function __invoke(string $product): JsonResponse
    {
        $this->products->archive($this->products->findForCompany($product));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
