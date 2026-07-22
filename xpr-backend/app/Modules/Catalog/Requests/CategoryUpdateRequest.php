<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Requests;

/**
 * Mise à jour d'une catégorie : mêmes règles qu'à la création, sauf que la
 * fiche modifiée ne doit pas entrer en conflit avec elle-même sur son nom.
 */
final class CategoryUpdateRequest extends CategoryStoreRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $rules = parent::rules();

        // PATCH partiel : le nom n'a pas à être retransmis pour changer une
        // couleur. `sometimes` conserve les autres contraintes quand il l'est.
        $rules['name'] = ['sometimes', ...array_slice($rules['name'], 1)];

        return $rules;
    }

    protected function ignoredId(): ?string
    {
        // Toujours une chaîne : la route déclare {category} en paramètre simple,
        // pas en binding de modèle (cf. RouteBindingScopeTest).
        $category = $this->route('category');

        return is_string($category) ? $category : null;
    }
}
