<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\RequiredIf;

/**
 * Création d'un document commercial.
 *
 * Ce qui N'EST PAS accepté ici est aussi important que ce qui l'est :
 *  - **pas de `status`**, sauf sur une situation — un document naît brouillon,
 *    l'émission est une action explicite qui consomme un numéro (§3). La
 *    situation, qui n'a pas d'étape d'émission, porte un état d'avancement
 *    saisi par l'utilisateur ;
 *  - **`number` est accepté depuis le 2026-08-14**, en CHIFFRES seulement et à
 *    la création uniquement. Il était jusque-là refusé par principe (§3 : le
 *    numéro vient de `sequences`, il ne se choisit pas). Ce que la levée
 *    coûte est documenté dans `DocumentWriteService::resolveNumber()` ;
 *  - **pas de totaux** — ils sont recalculés depuis les lignes. Les accepter
 *    permettrait de facturer un montant sans rapport avec le détail affiché.
 *
 * Les bornes numériques reprennent celles de DocumentCalculator : elles
 * protègent le calcul du dépassement d'entier 64 bits.
 */
class DocumentStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->requireId();

        return [
            'type' => ['required', Rule::enum(DocumentType::class)],

            // Le tiers doit appartenir à la SOCIÉTÉ ACTIVE : sans ce filtre, un
            // identifiant deviné rattacherait le document au client d'une autre
            // société (§5.3). Le company_id vient du contexte tenant.
            'partnerId' => [
                // Obligatoire sur une SITUATION : l'écran « situations par
                // client » et ses quatre indicateurs agrègent sur `partner_id`.
                // Une situation à client libre serait invisible de cet écran,
                // donc absente des totaux — un reste à payer faux vaut moins
                // qu'un champ obligatoire.
                ...$this->requiredOnSituation(),
                'nullable',
                'uuid',
                Rule::exists('partners', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            // Requis seulement en l'absence de tiers : quand un tiers est
            // choisi, le service recopie son identité légale sur le document.
            'clientName' => ['required_without:partnerId', 'nullable', 'string', 'min:2', 'max:255'],

            // PROJET facturé, facultatif : la plupart des pièces n'en relèvent
            // d'aucun. Scopé à la société comme le tiers.
            //
            // Ce que cette règle NE vérifie PAS : que le projet appartienne au
            // même client que le document. La condition porte sur
            // `projects.partner_id` face à un tiers qui, en saisie au nom
            // libre, n'existe pas encore au moment de la validation — c'est le
            // service qui le résout. La cohérence est donc tenue par
            // `DocumentWriteService`, seul endroit où les deux sont connus.
            'projectId' => [
                'nullable',
                'uuid',
                Rule::exists('projects', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],

            // NUMÉRO SAISI À LA MAIN. Optionnel : vide, la séquence reprend la
            // main et attribue le suivant.
            //
            // `regex` et non `numeric` : ce dernier accepte `-1`, `12.5` et
            // `1e3`, qui produiraient des numéros de pièce absurdes. Le champ
            // est traité comme une CHAÎNE de chiffres et stocké tel quel —
            // « 007 » reste « 007 », le caster en entier le transformerait en
            // « 7 » sous les doigts de l'utilisateur.
            //
            // L'unicité est vérifiée par société ET en ignorant les documents
            // supprimés, exactement comme l'index partiel
            // `documents_company_number_unique` : une règle plus stricte que la
            // base rejetterait des numéros que la base accepte, une règle plus
            // lâche laisserait remonter une erreur SQL brute en 500.
            //
            // Cette validation ne remplace PAS l'index : entre le contrôle et
            // l'INSERT, une autre requête peut prendre le numéro. C'est le
            // service qui rattrape ce cas, en 409.
            'number' => [
                'nullable',
                'string',
                'regex:/^\d{1,20}$/',
                Rule::unique('documents', 'number')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],

            'issuedAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

            // Objet du document. Obligatoire sur une SITUATION, où il porte
            // seul le sens de la pièce (« Situation du mois d'octobre ») :
            // sans lignes de détail, un objet vide donnerait un document muet.
            'subject' => [
                ...$this->requiredOnSituation(),
                'nullable',
                'string',
                'max:255',
            ],

            // Ville d'établissement du document (« RABAT, le … » en tête du
            // devis imprimé). Libre : c'est celle du chantier, pas du siège, et
            // aucun référentiel de communes marocaines n'a sa place ici.
            'issueCity' => ['nullable', 'string', 'max:100'],

            // Montant GLOBAL, réservé aux types sans lignes.
            //
            // Sur tout autre type, le champ est IGNORÉ et non rejeté : c'est le
            // traitement déjà réservé à `status`, `number` et `subtotalCents`,
            // et il vaut mieux qu'un refus. `DocumentWriteService` filtre par
            // `hasGlobalAmount()`, en interrogeant le type du document PERSISTÉ
            // — un `totalCents` posté sur une facture n'a donc aucun chemin
            // vers ses totaux, qui restent la somme de ses lignes.
            'totalCents' => [
                ...$this->requiredOnSituation(),
                'nullable',
                'integer',
                'min:0',
                'max:9999999999999',
            ],

            // Avance déjà encaissée. Même règle d'ignorance hors situation. Le
            // plafond « pas plus que le total » est vérifié dans
            // withValidator() : il compare deux champs.
            'paidCents' => [
                'nullable',
                'integer',
                'min:0',
                'max:9999999999999',
            ],

            // État de la situation, SAISI et non déduit.
            //
            // Le champ reste ignoré sur tout autre type : l'état d'une facture
            // est la conséquence de son cycle de vie, et l'accepter en entrée
            // permettrait d'en créer une « payée » sans qu'aucun règlement
            // n'ait eu lieu. Le service refiltre de toute façon par type.
            //
            // Les valeurs recevables viennent de `manuallyAssignableFor()`, qui
            // écarte `draft` (le document est numéroté), `cancelled` et
            // `converted` (endpoints dédiés, avec leurs propres règles).
            // Facultatif : l'omettre laisse l'état se déduire de l'avance,
            // comportement d'origine que les appels existants conservent.
            'status' => [
                'nullable',
                Rule::in(array_map(
                    static fn (DocumentStatus $status): string => $status->value,
                    DocumentStatus::manuallyAssignableFor(DocumentType::Situation),
                )),
            ],

            // AU MOINS UNE LIGNE sur les types qui se numérotent à la création
            // (facture, devis depuis le 2026-08-14).
            //
            // C'était l'ÉMISSION qui portait cette exigence, du temps où elle
            // portait aussi le numéro : un document vide ne doit pas consommer
            // une place dans la séquence pour ne rien attester. La règle n'a pas
            // changé, c'est le moment où elle s'applique qui a suivi le numéro.
            // Elle est doublée côté service (`assertHasContent()`), qui répond
            // 409 ; la voir ici évite un aller-retour et rend l'erreur 422
            // adressable au champ.
            //
            // Une situation, elle, n'accepte aucune ligne : son montant est
            // global. Les types d'achat et d'expédition gardent l'ancien
            // comportement — créés vides, complétés, puis émis.
            'items' => [
                Rule::prohibitedIf(fn (): bool => $this->isSituation()),
                Rule::requiredIf(fn (): bool => $this->numbersOnCreate()),
                'nullable',
                'array',
                'min:'.($this->numbersOnCreate() ? 1 : 0),
                'max:200',
            ],
            'items.*.productId' => [
                'nullable',
                'uuid',
                Rule::exists('products', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            // Le libellé n'est obligatoire que sur une ligne libre : sinon il
            // est recopié depuis l'article.
            'items.*.label' => ['required_without:items.*.productId', 'nullable', 'string', 'max:255'],
            'items.*.description' => ['nullable', 'string', 'max:2000'],
            // Bornes alignées sur DocumentCalculator::MAX_QUANTITY_MILLI.
            'items.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unitPriceCents' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'items.*.discountPercent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            // Le taux applicable est RÉSOLU depuis cet identifiant, jamais reçu
            // en clair : un client ne doit pas pouvoir déclarer « 0 % » sur une
            // prestation taxée à 20 %.
            'items.*.taxRateId' => [
                'nullable',
                'uuid',
                Rule::exists('tax_rates', 'id')
                    ->where(function ($query) use ($companyId) {
                        // NULL = catalogue standard marocain, partagé par
                        // toutes les sociétés (cf. migration des tax_rates).
                        $query->whereNull('company_id')->orWhere('company_id', $companyId);
                    })
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Refuse une avance supérieure au montant de la situation.
     *
     * Ici et pas dans `rules()` : la règle compare DEUX champs, ce qu'aucune
     * règle unitaire ne sait faire. `lte:totalCents` existe, mais compare les
     * valeurs brutes du payload sans garantir que l'autre champ soit un entier
     * valide — sur une saisie « abc » il produirait un message incompréhensible.
     *
     * La comparaison n'a lieu que si les deux champs sont présents ET entiers.
     * Le PATCH partiel (« je ne change que l'avance ») échappe donc à ce
     * contrôle : c'est DocumentWriteService qui le rattrape, avec l'état
     * existant sous les yeux.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->isSituation()) {
                return;
            }

            $total = $this->input('totalCents');
            $paid = $this->input('paidCents');

            if (! is_int($total) || ! is_int($paid)) {
                return;
            }

            if ($paid > $total) {
                $validator->errors()->add(
                    'paidCents',
                    __('The advance cannot exceed the total amount.'),
                );
            }
        });
    }

    /**
     * Le document visé est-il une SITUATION ?
     *
     * Détermine à lui seul quels champs deviennent obligatoires, lesquels sont
     * interdits, et si les lignes de détail sont acceptées. Le type est lu dans
     * le PAYLOAD : à la création, c'est la seule source disponible.
     * DocumentUpdateRequest le surcharge — en PATCH le type ne circule pas.
     */
    protected function isSituation(): bool
    {
        return $this->input('type') === DocumentType::Situation->value;
    }

    /**
     * Le type visé se numérote-t-il dès la création, ET porte-t-il des lignes ?
     *
     * La situation numérote elle aussi d'office, mais son contenu est un
     * montant global : elle est écartée ici, ses propres règles
     * (`requiredOnSituation()` sur `totalCents`) tiennent le même rôle.
     *
     * Le type est lu dans le PAYLOAD, comme `isSituation()` : à la création,
     * c'est la seule source disponible. Un type absent ou inconnu renvoie
     * `false` — c'est la règle `type` qui rejettera la requête, pas celle-ci.
     */
    protected function numbersOnCreate(): bool
    {
        $type = DocumentType::tryFrom((string) $this->input('type'));

        return $type !== null && $type->numbersOnCreate() && ! $type->hasGlobalAmount();
    }

    /**
     * Fragment de règles rendant un champ obligatoire sur une situation.
     *
     * Renvoyé sous forme de LISTE à étaler (`...`) plutôt que d'une règle
     * unique : DocumentUpdateRequest le neutralise en renvoyant un tableau
     * vide, ce qu'une règle unique ne permettrait pas — il faudrait alors
     * filtrer les règles du parent par `instanceof`, un couplage qui casserait
     * au premier changement de forme.
     *
     * @return list<RequiredIf>
     */
    protected function requiredOnSituation(): array
    {
        return [Rule::requiredIf(fn (): bool => $this->isSituation())];
    }
}
