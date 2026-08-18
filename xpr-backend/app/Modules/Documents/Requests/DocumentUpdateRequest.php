<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Models\Document;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

/**
 * Mise à jour d'un document. Mêmes règles qu'à la création, sauf deux champs :
 *
 *  - le `type` reste RETIRÉ : muter un devis en facture contournerait la
 *    numérotation et la matrice d'états ;
 *  - le `number` est ADMIS depuis le 2026-08-18, pour la facture et le devis
 *    seulement (`DocumentType::allowsNumberEdit()`, qui porte le coût de cette
 *    levée). Sur tout autre type, il est ignoré comme auparavant.
 *
 * ── Trois différences avec la règle de création ──────────────────────────
 *
 * 1. L'unicité IGNORE le document lui-même : sans cela, réenregistrer un
 *    formulaire sans toucher au numéro le ferait rejeter pour son propre
 *    compte.
 * 2. Le FORMAT est plus large. À la création, le champ n'accepte que des
 *    chiffres — c'est un compteur qu'on impose à une pièce qui n'a pas encore
 *    d'identité. En modification, le champ porte le numéro TEL QU'IL S'AFFICHE
 *    (« FAC-2026-0003 ») : n'y accepter que des chiffres transformerait une
 *    correction en remplacement par « 4 », ce que personne ne demande.
 * 3. Il n'est jamais VIDABLE. Une pièce numérotée ne redevient pas brouillon :
 *    rendre le numéro à null le libérerait pour une autre pièce tout en
 *    laissant celle-ci dans la nature avec son ancien numéro imprimé.
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

        $rules['number'] = $this->numberRules();

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
     * Règles du champ `number` en modification.
     *
     * `prohibited` sur un type qui n'ouvre pas la renumérotation, et non un
     * simple retrait : un champ silencieusement ignoré laisse l'appelant croire
     * que son numéro a été pris. Le refus est explicite et porte sur le champ.
     *
     * Le document n'est pas résolu une seconde fois — `resolveDocument()` le
     * mémorise, ces règles et `isSituation()` se partageant la même instance.
     *
     * @return list<mixed>
     */
    private function numberRules(): array
    {
        $document = $this->resolveDocument();

        // Introuvable sous le scope tenant : le contrôleur répondra 404. On
        // interdit le champ plutôt que d'écrire une règle `unique` sur un
        // identifiant qu'on n'a pas.
        if (! $document instanceof Document || ! $document->type->allowsNumberEdit()) {
            return ['prohibited'];
        }

        return [
            // Ni `nullable` ni `sometimes` vide : une pièce numérotée ne
            // redevient pas brouillon. La clé ABSENTE laisse le numéro intact,
            // c'est la seule façon de ne pas y toucher.
            'sometimes',
            'required',
            'string',
            // Le vocabulaire d'un numéro de pièce : lettres, chiffres, et les
            // séparateurs qu'on y trouve. Volontairement plus strict qu'un
            // texte libre — un numéro doit rester citable au téléphone et
            // reconnaissable sur un relevé.
            'regex:/^[A-Za-z0-9][A-Za-z0-9\/\-_.]{0,29}$/',
            Rule::unique('documents', 'number')
                ->where('company_id', $document->company_id)
                ->whereNull('deleted_at')
                // Sans quoi le document serait rejeté pour SON PROPRE numéro
                // dès qu'on réenregistre le formulaire sans y toucher.
                ->ignore($document->id),
        ];
    }

    /**
     * Le document visé, résolu UNE fois sous le scope tenant.
     *
     * Le middleware `tenant` s'exécute avant la résolution du FormRequest : un
     * identifiant d'une autre société ne renvoie donc rien, et aucune règle ne
     * peut être construite à partir de sa société (§5).
     */
    private function resolveDocument(): ?Document
    {
        if ($this->document === false) {
            $id = $this->route('document');

            $this->document = is_string($id)
                ? Document::query()->find($id)
                : null;
        }

        return $this->document;
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
        return $this->resolveDocument()?->type->hasGlobalAmount() ?? false;
    }

    /**
     * Toujours `false` en PATCH : l'exigence « au moins une ligne » vise la
     * CRÉATION, moment où le numéro est consommé. Un document déjà numéroté
     * l'a nécessairement honorée, et la réimposer ici obligerait à
     * retransmettre toutes les lignes pour corriger une simple note.
     *
     * Surcharge EXPLICITE plutôt que de laisser le parent lire un `type` absent
     * du payload : un appelant qui en enverrait un — le service l'ignore, mais
     * la validation le verrait — réactiverait l'exigence par accident.
     */
    protected function numbersOnCreate(): bool
    {
        return false;
    }
}
