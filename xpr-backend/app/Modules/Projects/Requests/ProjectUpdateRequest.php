<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correction d'un projet, y compris le seul changement d'avancement.
 *
 * TOUS les champs sont `sometimes` : le service n'écrit que les clés présentes,
 * ce qui permet à la section « avancement » de l'écran de détail de ne pousser
 * que `status` et `progressPercentage` sans renvoyer le reste de la fiche.
 *
 * Une classe distincte de la création, et non un `FormRequest` partagé : sans
 * `sometimes`, un PATCH qui omet le titre le remettrait à vide, et avec
 * `sometimes` partout un POST accepterait un projet sans client.
 */
final class ProjectUpdateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partnerId' => ['sometimes', 'required', 'uuid'],
            'title' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            // `sometimes` + `nullable` : la clé ABSENTE laisse le classement
            // intact, la clé à null le retire. Un projet mal classé doit
            // pouvoir être déclassé sans qu'on ait à lui trouver un autre
            // service.
            'serviceId' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('services', 'id')
                    ->where('company_id', app(TenantContext::class)->requireId())
                    ->whereNull('deleted_at'),
            ],
            'status' => ['sometimes', 'required', Rule::enum(ProjectStatus::class)],
            // Miroir de la contrainte CHECK `projects_progress_range_check` :
            // ici pour rendre un 422 lisible plutôt qu'une violation SQL brute
            // remontée en 500.
            'progressPercentage' => ['sometimes', 'required', 'integer', 'between:0,100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
