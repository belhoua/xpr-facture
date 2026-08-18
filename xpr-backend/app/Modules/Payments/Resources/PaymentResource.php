<?php

declare(strict_types=1);

namespace App\Modules\Payments\Resources;

use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un règlement.
 *
 * `scanPath` n'est JAMAIS exposé : c'est un chemin de disque, et le divulguer
 * inviterait un client à le composer lui-même. L'API rend une URL d'endpoint
 * authentifié, ou `null` — et le nom d'origine, qui sert d'étiquette.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoiceId' => $this->invoice_id,
            'amountCents' => $this->amount_cents,
            'currency' => $this->currency,
            'paidOn' => $this->paid_on->toDateString(),
            'method' => $this->method->value,
            'reference' => $this->reference,
            'notes' => $this->notes,
            'checkNumber' => $this->check_number,
            'bankDepositDate' => $this->bank_deposit_date?->toDateString(),
            'receivedDate' => $this->received_date?->toDateString(),
            'checkStatus' => $this->check_status?->value,
            'scanName' => $this->scan_name,
            'scanUrl' => $this->hasScan()
                ? url("/api/v1/payments/{$this->id}/scan")
                : null,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
