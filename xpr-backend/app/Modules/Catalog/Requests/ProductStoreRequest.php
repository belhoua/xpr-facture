<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Requests;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Création d'un article du catalogue.
 *
 * Deux règles portent la sécurité multi-tenant (§5.3) et ne sont pas
 * facultatives : la catégorie ET le taux de TVA doivent appartenir au périmètre
 * de la société active. Sans ce filtre, un identifiant deviné rattacherait un
 * article à la catégorie d'une autre société.
 */
class ProductStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->requireId();

        return [
            'categoryId' => [
                'nullable',
                'uuid',
                Rule::exists('categories', 'id')
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at'),
            ],
            'type' => ['required', Rule::enum(ProductType::class)],
            'reference' => ['nullable', 'string', 'max:40', $this->uniqueReference()],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['nullable', 'string', 'max:20'],

            // Montants EN CENTIMES (entiers) — jamais de flottant (§7).
            'unitPriceCents' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'costPriceCents' => ['nullable', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],

            // Remise par défaut : un POURCENTAGE, donc pas un montant en
            // centimes. Bornes et précision alignées sur
            // `items.*.discountPercent` du DocumentStoreRequest — la valeur est
            // recopiée telle quelle sur la ligne, elle doit y être recevable.
            'defaultDiscountPercent' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],

            // Le catalogue standard (company_id NULL) est accessible à toutes
            // les sociétés — c'est le principe posé par la migration des
            // tax_rates. Une société peut aussi pointer un taux qui lui est propre.
            'taxRateId' => [
                'nullable',
                'uuid',
                Rule::exists('tax_rates', 'id')
                    ->where(function ($query) use ($companyId) {
                        $query->whereNull('company_id')->orWhere('company_id', $companyId);
                    })
                    ->whereNull('deleted_at'),
            ],

            // La cohérence « service ⇒ pas de stock » est appliquée par le
            // service, pas rejetée ici : basculer un bien suivi en stock vers
            // un service est une correction normale, pas une erreur de saisie.
            'trackStock' => ['nullable', 'boolean'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Unicité de la référence dans la SOCIÉTÉ ACTIVE, jamais globale : deux
     * sociétés ont le droit d'utiliser le même code article. Les fiches
     * archivées sont ignorées, comme dans l'index partiel en base.
     */
    protected function uniqueReference(): Unique
    {
        return Rule::unique('products', 'reference')
            ->where('company_id', app(TenantContext::class)->requireId())
            ->whereNull('deleted_at');
    }
}
