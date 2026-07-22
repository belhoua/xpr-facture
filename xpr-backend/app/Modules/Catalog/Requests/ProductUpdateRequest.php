<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Requests;

use Illuminate\Validation\Rules\Unique;

/**
 * Mise à jour d'un article : mêmes règles qu'à la création, à ceci près que la
 * fiche modifiée ne doit pas entrer en conflit avec sa propre référence.
 */
final class ProductUpdateRequest extends ProductStoreRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        // PATCH partiel : changer le seul prix ne doit pas exiger de renvoyer
        // le type et le libellé. `sometimes` conserve les autres contraintes
        // quand le champ est bien transmis.
        foreach (['type', 'name', 'unitPriceCents'] as $field) {
            $rules[$field] = ['sometimes', ...array_slice($rules[$field], 1)];
        }

        return $rules;
    }

    protected function uniqueReference(): Unique
    {
        // Toujours une chaîne : la route déclare {product} en paramètre simple,
        // pas en binding de modèle (cf. RouteBindingScopeTest).
        $product = $this->route('product');

        return parent::uniqueReference()->ignore(is_string($product) ? $product : null);
    }
}
