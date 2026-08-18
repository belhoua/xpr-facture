<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\PaymentWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retire un règlement.
 *
 * *Soft delete* : la ligne demeure avec son `deleted_at`, et la facture est
 * réalignée dans la même transaction — une facture « payée » dont on retire le
 * règlement doit redevenir « envoyée » au même instant, jamais dans une
 * seconde requête que rien ne garantit.
 */
final class PaymentDeleteController
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentWriteService $writes,
    ) {}

    public function __invoke(string $payment): JsonResponse
    {
        $this->writes->delete($this->payments->findForCompany($payment));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
