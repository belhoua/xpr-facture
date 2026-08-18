<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ajout d'un livrable remis au client.
 *
 * Le `projectId` n'est PAS dans ce payload : il vient du chemin de la route
 * (`projects/{project}/deliverables`) et le projet est résolu sous le scope
 * tenant avant que le service ne soit appelé. L'accepter dans le corps de la
 * requête ouvrirait la possibilité d'attacher une remise au projet d'une autre
 * société (§5.3).
 *
 * Le titre est LIBRE et non un enum : « Notice technique », « Rapport
 * d'avancement », « Procès-verbal » sont des exemples, pas une nomenclature —
 * la figer ferait refuser un intitulé légitime dès le premier métier dont le
 * vocabulaire diffère.
 */
final class DeliverableStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:255'],
            // Aucune borne haute : une remise se date volontiers à l'avance,
            // quand la date est convenue mais le document pas encore parti.
            'deliveryDate' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
