<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Services\InvoiceWriteService;
use Illuminate\Http\Response;

/**
 * Résolution dans le contrôleur (après `tenant`) pour rester sous le scope
 * BelongsToCompany — cf. InvoiceUpdateController.
 */
final class InvoiceDeleteController
{
    public function __construct(private readonly InvoiceWriteService $invoices) {}

    public function __invoke(string $invoice): Response
    {
        $model = Invoice::query()->findOrFail($invoice);

        $this->invoices->delete($model);

        return response()->noContent();
    }
}
