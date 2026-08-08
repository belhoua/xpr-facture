<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Models\Document;
use Illuminate\Validation\Rules\RequiredIf;

/**
 * Mise à jour d'un document. Mêmes règles qu'à la création, sauf le `type` :
 * muter un devis en facture contournerait la numérotation et la matrice
 * d'états, le service l'ignore de toute façon.
 */
final class DocumentUpdateRequest extends DocumentStoreRequest
{
    /**
     * Document visé, résolu une seule fois par requête.
     *
     * `false` distingue « pas encore cherché » de « cherché, introuvable » —
     * `null` ne saurait pas faire la différence et relancerait la requête à
     * chaque appel, soit une fois par champ conditionnel.
     */
    private Document|false|null $document = false;

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

    /**
     * Aucun champ n'est obligatoire en PATCH — c'est la définition d'une mise à
     * jour partielle. Les obligations de la création (tiers, objet, montant)
     * ont déjà été honorées ; le document ne peut pas les avoir perdues.
     *
     * @return list<RequiredIf>
     */
    protected function requiredOnSituation(): array
    {
        return [];
    }

    /**
     * Le type vient du DOCUMENT EXISTANT, jamais du payload : `type` n'est pas
     * modifiable, et le lire dans la requête permettrait de faire passer une
     * facture pour une situation le temps d'une validation — donc d'y injecter
     * un `totalCents` arbitraire, sans rapport avec ses lignes.
     *
     * La résolution se fait sous le scope tenant : le middleware `tenant`
     * s'exécute avant que le FormRequest ne soit résolu, un identifiant d'une
     * autre société ne renvoie donc rien (et les règles retombent sur le cas
     * « pas une situation », le contrôleur répondant ensuite 404).
     */
    protected function isSituation(): bool
    {
        if ($this->document === false) {
            $id = $this->route('document');

            $this->document = is_string($id)
                ? Document::query()->find($id)
                : null;
        }

        return $this->document?->type->hasGlobalAmount() ?? false;
    }
}
