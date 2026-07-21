<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Requests\InvoiceUpdateRequest;
use App\Modules\Invoices\Resources\InvoiceResource;
use App\Modules\Invoices\Services\InvoiceWriteService;
use Illuminate\Http\JsonResponse;

/**
 * La facture est résolue ICI, dans le contrôleur — donc APRÈS le middleware
 * `tenant` — et non par binding implicite : SubstituteBindings s'exécute avant
 * `tenant`, il résoudrait le modèle hors du scope BelongsToCompany et ouvrirait
 * un accès inter-sociétés. `findOrFail` sous le scope actif renvoie 404 pour une
 * facture d'une autre société (§5).
 */
final class InvoiceUpdateController
{
    public function __construct(private readonly InvoiceWriteService $invoices) {}

    public function __invoke(InvoiceUpdateRequest $request, string $invoice): JsonResponse
    {
        $model = Invoice::query()->findOrFail($invoice);

        /** @var array{clientName: string, issuedAt: ?string, dueAt: ?string, status: string, totalCents: int, currency: string} $data */
        $data = $request->validated();

        return response()->json(
            (new InvoiceResource($this->invoices->update($model, $data)))->resolve(),
        );
    }
}
