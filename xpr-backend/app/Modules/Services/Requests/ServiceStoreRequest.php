<?php

declare(strict_types=1);

namespace App\Modules\Services\Requests;

use App\Modules\Services\Models\Service;
use App\Modules\Tenancy\Services\TenantContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Création d'un service du référentiel.
 *
 * L'unicité du nom est vérifiée PAR SOCIÉTÉ, hors archivés, et SANS ÉGARD À LA
 * CASSE NI AUX ESPACES DE BORD — exactement comme l'index partiel
 * `services_company_name_unique`, qui porte sur `lower(btrim(name))`.
 *
 * D'où une closure plutôt qu'un `Rule::unique` : celui-ci compare `name = ?`,
 * une égalité stricte que PostgreSQL n'applique pas ici. La validation aurait
 * laissé passer « diagnostic structure » face à « Diagnostic structure », et
 * l'index l'aurait refusé — en 500, sur un doublon que l'écran aurait dû
 * signaler en 422.
 */
class ServiceStoreRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $companyId = app(TenantContext::class)->requireId();

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail) use ($companyId): void {
                    $taken = Service::query()
                        ->withoutGlobalScopes()
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->whereRaw('lower(btrim(name)) = ?', [mb_strtolower(trim((string) $value))])
                        ->exists();

                    if ($taken) {
                        $fail(__('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }
}
