<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Resources;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat JSON de la société. Version "identité" : les champs fiscaux complets
 * (ICE, IF, RC…) s'exposent ici au fur et à mesure des besoins des écrans.
 *
 * @mixin Company
 */
final class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'legal_form' => $this->legal_form,
            'ice' => $this->ice,
            'vat_exempt' => $this->vat_exempt,
            'default_currency' => $this->default_currency,
            'country' => $this->country,
            'timezone' => $this->timezone,
        ];
    }
}
