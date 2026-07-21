<?php

declare(strict_types=1);

namespace App\Modules\Cash\Resources;

use App\Modules\Cash\Models\CashMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CashMovement
 */
final class CashMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurredAt' => $this->occurred_at->toDateString(),
            'label' => $this->label,
            'method' => $this->method,
            'registerName' => $this->register_name,
            'amountCents' => $this->amount_cents,
            'currency' => $this->currency,
        ];
    }
}
