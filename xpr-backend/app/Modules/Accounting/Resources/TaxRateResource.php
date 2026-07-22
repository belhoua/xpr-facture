<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\TaxRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Taux de TVA. `rate` sort en CHAÎNE (« 20.00 ») et non en nombre : c'est un
 * décimal exact, et le front ne s'en sert qu'à l'affichage — les montants sont
 * calculés par le serveur (§7).
 *
 * `isGlobal` distingue le catalogue standard marocain, partagé et non
 * modifiable par la société, des taux qu'elle a elle-même créés.
 *
 * @mixin TaxRate
 */
final class TaxRateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'labelFr' => $this->label_fr,
            'labelAr' => $this->label_ar,
            'rate' => (string) $this->rate,
            'kind' => $this->kind->value,
            'isDefault' => $this->is_default,
            'isGlobal' => $this->company_id === null,
        ];
    }
}
