<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'un projet.
 *
 * Le `partnerId` est validé comme un UUID mais SON EXISTENCE ne l'est pas ici :
 * `Rule::exists('partners', 'id')` interroge la table sans le global scope de
 * société et accepterait le client d'un autre tenant. La vérification revient à
 * `ProjectWriteService`, qui la fait sous le scope (§5.3).
 */
final class ProjectStoreRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'partnerId' => ['required', 'uuid'],
            'title' => ['required', 'string', 'min:2', 'max:255'],
            // SERVICE facultatif : le référentiel naît vide, et exiger un
            // classement dès l'ouverture obligerait à inventer une entrée pour
            // créer le premier projet. Le `nullable` couvre aussi bien la clé
            // absente que la valeur nulle — « Aucun » dans le déroulant.
            //
            // Scopé à la SOCIÉTÉ ACTIVE : sans ce filtre, un identifiant deviné
            // classerait le projet sous le service d'une autre société (§5.3).
            'serviceId' => [
                'nullable',
                'uuid',
                Rule::exists('services', 'id')
                    ->where('company_id', app(TenantContext::class)->requireId())
                    ->whereNull('deleted_at'),
            ],
            // Facultatifs : un projet se crée d'un titre et d'un client, et
            // exiger l'état d'avancement dès l'ouverture ferait saisir un
            // chiffre que personne ne connaît encore. Le défaut est « en
            // cours », à 0 %.
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'progressPercentage' => ['nullable', 'integer', 'between:0,100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
