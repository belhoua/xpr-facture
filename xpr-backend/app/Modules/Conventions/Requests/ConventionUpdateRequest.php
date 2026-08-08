<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Requests;

use Illuminate\Validation\Rules\Unique;

/**
 * Mise à jour d'une convention : mêmes règles qu'à la création, à ceci près que
 * la convention modifiée ne doit pas entrer en conflit avec elle-même sur son
 * propre n° de dossier.
 */
final class ConventionUpdateRequest extends ConventionStoreRequest
{
    /**
     * `sometimes` : une mise à jour PARTIELLE est légitime — on corrige le seul
     * titre foncier bien plus souvent qu'on ne réécrit le contrat entier. Le
     * `required` subsiste derrière : transmettre un maître d'ouvrage vide reste
     * refusé, c'est l'OMISSION qui devient permise.
     *
     * @return list<string>
     */
    protected function requiredOnCreate(): array
    {
        return ['sometimes', 'required'];
    }

    protected function uniqueDossierNumber(): Unique
    {
        // Toujours une chaîne : la route déclare {convention} en paramètre
        // simple, pas en binding de modèle (cf. RouteBindingScopeTest).
        $convention = $this->route('convention');

        return parent::uniqueDossierNumber()->ignore(
            is_string($convention) ? $convention : null,
        );
    }
}
