<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Resources;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un article. Contrat plat en camelCase.
 *
 * Le taux de TVA est renvoyé DÉVELOPPÉ (libellé + valeur) et pas seulement par
 * son identifiant : le formulaire de document en a besoin pour pré-remplir la
 * ligne sans requête supplémentaire, et la liste pour afficher « 20 % ».
 *
 * @mixin Product
 */
final class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $category = $this->whenLoaded('category');
        $taxRate = $this->whenLoaded('taxRate');

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'reference' => $this->reference,
            'name' => $this->name,
            'description' => $this->description,
            'unit' => $this->unit,
            'unitPriceCents' => $this->unit_price_cents,
            'costPriceCents' => $this->cost_price_cents,
            'marginCents' => $this->marginCents(),
            'currency' => $this->currency,
            'categoryId' => $this->category_id,
            'categoryName' => $category instanceof Category ? $category->name : null,
            'categoryColor' => $category instanceof Category ? $category->color : null,
            'taxRateId' => $this->tax_rate_id,
            // Chaîne exacte issue du cast decimal:2 (« 20.00 »), jamais un
            // float : le front la reformate, il ne recalcule pas la TVA (§7).
            'taxRateValue' => $taxRate instanceof TaxRate ? (string) $taxRate->rate : null,
            'taxRateLabel' => $taxRate instanceof TaxRate ? $taxRate->label_fr : null,
            'trackStock' => $this->track_stock,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
