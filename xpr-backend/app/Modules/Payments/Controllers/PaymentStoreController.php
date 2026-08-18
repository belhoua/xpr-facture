<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Modules\Payments\Requests\PaymentStoreRequest;
use App\Modules\Payments\Resources\PaymentResource;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enregistre un règlement sur une facture.
 *
 * Le contrôleur n'orchestre que trois gestes : résoudre la facture sous le
 * scope tenant, passer la charge utile au service, sérialiser. Les règles — la
 * facture doit être émise et non annulée, le cumul et le statut se recalculent
 * — vivent dans `PaymentWriteService` (§6).
 */
final class PaymentStoreController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentWriteService $writes,
    ) {}

    public function __invoke(PaymentStoreRequest $request, string $invoice): JsonResponse
    {
        $document = $this->payments->findInvoiceForCompany($invoice);

        $payment = $this->writes->create(
            $document,
            $request->payload(),
            $request->file('scan'),
        );

        return response()->json(
            (new PaymentResource($payment))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
