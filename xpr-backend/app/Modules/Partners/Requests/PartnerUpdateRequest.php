<?php

declare(strict_types=1);

namespace App\Modules\Partners\Requests;

use Illuminate\Validation\Rules\Unique;

/**
 * Mise à jour d'un tiers : mêmes règles qu'à la création, à ceci près que la
 * fiche modifiée ne doit pas entrer en conflit avec elle-même sur son propre
 * ICE ou son propre code.
 */
final class PartnerUpdateRequest extends PartnerStoreRequest
{
    protected function uniquePerCompany(string $column): Unique
    {
        // Toujours une chaîne : la route déclare {partner} en paramètre simple,
        // pas en binding de modèle (cf. RouteBindingScopeTest).
        $partner = $this->route('partner');

        return parent::uniquePerCompany($column)->ignore(
            is_string($partner) ? $partner : null,
        );
    }
}
