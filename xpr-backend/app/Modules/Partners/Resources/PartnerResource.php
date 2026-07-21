<?php

declare(strict_types=1);

namespace App\Modules\Partners\Resources;

use App\Modules\Partners\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un tiers. Contrat plat en camelCase, sans enveloppe `data` :
 * les contrôleurs renvoient `->resolve()`, comme le reste du dépôt.
 *
 * @mixin Partner
 */
final class PartnerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'code' => $this->code,
            'legalName' => $this->legal_name,
            'tradeName' => $this->trade_name,
            'displayName' => $this->displayName(),
            'legalForm' => $this->legal_form,
            'ice' => $this->ice,
            'ifNumber' => $this->if_number,
            'rcNumber' => $this->rc_number,
            'rcCity' => $this->rc_city,
            'patente' => $this->patente,
            'contactName' => $this->contact_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'currency' => $this->currency,
            'paymentTermsDays' => $this->payment_terms_days,
            'notes' => $this->notes,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
