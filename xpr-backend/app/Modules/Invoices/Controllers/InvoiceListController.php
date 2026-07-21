<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Modules\Invoices\Resources\InvoiceResource;
use App\Modules\Invoices\Services\InvoiceListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceListController
{
    public function __construct(private readonly InvoiceListService $invoices) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->invoices->paginate(
            search: $request->string('search')->toString() ?: null,
            status: $request->string('status')->toString() ?: null,
            page: max(1, $request->integer('page', 1)),
            perPage: max(1, min(100, $request->integer('perPage', 25))),
        );

        return response()->json([
            'data' => InvoiceResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
