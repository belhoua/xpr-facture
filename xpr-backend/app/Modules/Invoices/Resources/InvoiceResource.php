<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Resources;

use App\Modules\Invoices\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat camelCase aligné sur `features/invoices/schemas/invoice.ts`.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'clientName' => $this->client_name,
            'issuedAt' => $this->issued_at?->toDateString(),
            'dueAt' => $this->due_at?->toDateString(),
            'status' => $this->status,
            'totalCents' => $this->total_cents,
            'currency' => $this->currency,
        ];
    }
}
