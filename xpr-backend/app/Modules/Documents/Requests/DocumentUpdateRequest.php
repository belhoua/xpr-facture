<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

/**
 * Mise à jour d'un BROUILLON. Mêmes règles qu'à la création, sauf le `type` :
 * muter un devis en facture contournerait la numérotation et la matrice
 * d'états, le service l'ignore de toute façon.
 */
final class DocumentUpdateRequest extends DocumentStoreRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();

        unset($rules['type']);

        // PATCH partiel : ne changer qu'une note ne doit pas exiger de
        // retransmettre l'identité du client.
        $rules['clientName'] = ['nullable', 'string', 'min:2', 'max:255'];

        return $rules;
    }
}
