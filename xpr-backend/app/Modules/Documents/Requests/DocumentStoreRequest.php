<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un document commercial.
 *
 * Ce qui N'EST PAS accepté ici est aussi important que ce qui l'est :
 *  - **pas de `status`** — un document naît brouillon, l'émission est une
 *    action explicite qui consomme un numéro (§3) ;
 *  - **pas de `number`** — il est attribué par `sequences`, jamais choisi ;
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
                'nullable',
                'uuid',
                Rule::exists('partners', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            // Requis seulement en l'absence de tiers : quand un tiers est
            // choisi, le service recopie son identité légale sur le document.
            'clientName' => ['required_without:partnerId', 'nullable', 'string', 'min:2', 'max:255'],

            'issuedAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],

            // Un document peut être créé vide et complété ensuite ; c'est
            // l'ÉMISSION qui exige au moins une ligne.
            'items' => ['nullable', 'array', 'max:200'],
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
}
