<?php

declare(strict_types=1);

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\DocumentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ligne de document. Contrat plat en camelCase.
 *
 * `quantity`, `discountPercent` et `taxRate` sortent en CHAÎNES et non en
 * nombres : ce sont des décimaux exacts (casts `decimal:N`). Les convertir en
 * float ici réintroduirait dans le JSON l'imprécision binaire que toute la
 * chaîne de calcul évite (§7). Le front les formate, il ne les recalcule pas.
 *
 * @mixin DocumentItem
 */
final class DocumentItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'position' => $this->position,
            'label' => $this->label,
            'description' => $this->description,
            'quantity' => (string) $this->quantity,
            'unit' => $this->unit,
            'unitPriceCents' => $this->unit_price_cents,
            'discountPercent' => (string) $this->discount_percent,
            'taxRateId' => $this->tax_rate_id,
            // Taux FIGÉ à la saisie, pas celui du référentiel aujourd'hui.
            'taxRate' => (string) $this->tax_rate,
            'subtotalCents' => $this->subtotal_cents,
            'discountCents' => $this->discount_cents,
            'taxCents' => $this->tax_cents,
            'totalCents' => $this->total_cents,
        ];
    }
}
