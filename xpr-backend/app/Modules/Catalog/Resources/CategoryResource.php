<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Resources;

use App\Modules\Catalog\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat plat en camelCase, sans enveloppe `data` — convention du dépôt.
 *
 * @mixin Category
 */
final class CategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'color' => $this->color,
            'isActive' => $this->is_active,
            // Présent uniquement quand la liste a fait le withCount : une
            // valeur absente vaut mieux qu'un 0 qui laisserait croire que la
            // catégorie est vide.
            'productCount' => $this->whenCounted('products'),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
