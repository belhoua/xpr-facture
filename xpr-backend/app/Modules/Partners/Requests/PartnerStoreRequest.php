<?php

declare(strict_types=1);

namespace App\Modules\Partners\Requests;

use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Création d'un tiers. L'autorisation est portée par le middleware
 * `permission:partners.create` sur la route ; ce FormRequest ne valide que la
 * forme des données.
 *
 * L'unicité de l'ICE est vérifiée ICI en plus de l'index unique en base : la
 * base garantit l'intégrité, mais une violation en sortirait sous forme
 * d'erreur serveur illisible. La validation produit un message rattaché au
 * champ, exploitable par le formulaire.
 */
class PartnerStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(PartnerType::class)],
            'code' => ['nullable', 'string', 'max:30', $this->uniquePerCompany('code')],

            'legalName' => ['required', 'string', 'max:255'],
            'tradeName' => ['nullable', 'string', 'max:255'],
            'legalForm' => ['nullable', Rule::enum(LegalForm::class)],

            // 15 chiffres exactement, comme la contrainte CHECK en base (§3).
            'ice' => ['nullable', 'string', 'regex:/^[0-9]{15}$/', $this->uniquePerCompany('ice')],
            'ifNumber' => ['nullable', 'string', 'max:20'],
            'rcNumber' => ['nullable', 'string', 'max:20'],
            'rcCity' => ['nullable', 'string', 'max:100'],
            'patente' => ['nullable', 'string', 'max:20'],

            'contactName' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:1000'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2'],

            'currency' => ['nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Unicité dans le périmètre de la SOCIÉTÉ ACTIVE, et non globale : deux
     * sociétés distinctes ont parfaitement le droit d'avoir le même client.
     *
     * Le company_id vient du contexte tenant, jamais de la requête (§5.3). Les
     * fiches archivées sont ignorées, comme dans l'index partiel en base.
     */
    protected function uniquePerCompany(string $column): Unique
    {
        return Rule::unique('partners', $column)
            ->where('company_id', app(TenantContext::class)->requireId())
            ->whereNull('deleted_at');
    }
}
