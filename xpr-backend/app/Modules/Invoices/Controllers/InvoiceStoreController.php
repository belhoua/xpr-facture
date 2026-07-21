<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Controllers;

use App\Modules\Invoices\Requests\InvoiceStoreRequest;
use App\Modules\Invoices\Resources\InvoiceResource;
use App\Modules\Invoices\Services\InvoiceWriteService;
use Illuminate\Http\JsonResponse;

final class InvoiceStoreController
{
    public function __construct(private readonly InvoiceWriteService $invoices) {}

    public function __invoke(InvoiceStoreRequest $request): JsonResponse
    {
        /** @var array{clientName: string, issuedAt: ?string, dueAt: ?string, status: string, totalCents: int, currency: string} $data */
        $data = $request->validated();

        $invoice = $this->invoices->create($data);

        // ->resolve() : contrat PLAT sans enveloppe `data`, aligné sur le
        // schéma Zod du front et sur la convention du dépôt (InviteUserController).
        return response()->json((new InvoiceResource($invoice))->resolve(), 201);
    }
}
