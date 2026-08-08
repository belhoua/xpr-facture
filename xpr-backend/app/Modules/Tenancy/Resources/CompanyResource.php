<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Resources;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat JSON de la société : identité légale marocaine COMPLÈTE (§3).
 *
 * Elle l'est devenue pour l'impression des documents commerciaux, où l'en-tête
 * et le pied de page portent l'ICE, l'IF, le RC, la patente, la CNSS et les
 * coordonnées — mentions obligatoires que le client doit pouvoir lire sur le
 * papier. Rien ici n'est confidentiel : ce sont précisément les informations
 * que l'entreprise imprime sur ses devis et ses factures.
 *
 * `share_capital` reste en CENTIMES, comme en base (§7) : la division n'a lieu
 * qu'à l'affichage, côté client.
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
            'tagline' => $this->tagline,
            'legal_form' => $this->legal_form,

            // Immatriculations portées sur les documents commerciaux.
            'ice' => $this->ice,
            'if_number' => $this->if_number,
            'rc_number' => $this->rc_number,
            'rc_city' => $this->rc_city,
            'patente' => $this->patente,
            'cnss' => $this->cnss,
            'share_capital' => $this->share_capital,

            'vat_regime' => $this->vat_regime,
            'vat_exempt' => $this->vat_exempt,

            'address' => $this->address,
            'city' => $this->city,
            'country' => $this->country,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'bank_rib' => $this->bank_rib,

            'default_currency' => $this->default_currency,
            'timezone' => $this->timezone,
        ];
    }
}
