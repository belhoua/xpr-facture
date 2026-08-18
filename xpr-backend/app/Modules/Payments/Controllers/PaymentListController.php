<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Modules\Payments\Resources\PaymentResource;
use App\Modules\Payments\Services\PaymentService;
use Illuminate\Http\JsonResponse;

/**
 * Historique des règlements d'une facture, avec ses cumuls.
 *
 * Les trois indicateurs sont calculés ICI et non par l'écran : « reste à
 * payer » est une soustraction que deux clients (web, mobile) écriraient
 * chacun à leur façon, et un `max(0, …)` oublié afficherait un solde négatif
 * sur une facture trop-perçue.
 */
final class PaymentListController
{
    public function __construct(private readonly PaymentService $payments) {}

    public function __invoke(string $invoice): JsonResponse
    {
        $document = $this->payments->findInvoiceForCompany($invoice);
        $history = $this->payments->historyFor($document);

        return response()->json([
            'data' => PaymentResource::collection($history)->resolve(),
            'summary' => [
                'totalCents' => $document->total_cents,
                'paidCents' => $document->paid_cents,
                'remainingCents' => $document->remainingCents(),
                'currency' => $document->currency,
                'status' => $document->status->value,
            ],
        ]);
    }
}
