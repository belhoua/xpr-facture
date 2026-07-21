<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Resources\InvoiceResource;
use App\Modules\Invoices\Services\InvoiceWriteService;
use Illuminate\Http\JsonResponse;

/**
 * Résolution dans le contrôleur (après `tenant`) pour rester sous le scope
 * BelongsToCompany — cf. InvoiceUpdateController.
 */
final class InvoiceCancelController
{
    public function __construct(private readonly InvoiceWriteService $invoices) {}

    public function __invoke(string $invoice): JsonResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        return response()->json(
            (new InvoiceResource($this->invoices->cancel($model)))->resolve(),
        );
    }
}
