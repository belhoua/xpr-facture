<?php

declare(strict_types=1);

namespace App\Modules\Projects\Requests;

use App\Modules\Catalog\Enums\ProductType;
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
            // SERVICE facultatif : un projet peut ne relever d'aucune
            // prestation, et l'exiger obligerait une société qui ne classe pas
            // ses missions à inventer une entrée de catalogue pour créer son
            // premier projet. Le `nullable` couvre aussi bien la clé absente
            // que la valeur nulle — « Aucun » dans le déroulant.
            'serviceId' => self::serviceRules(),
            // Facultatifs : un projet se crée d'un titre et d'un client, et
            // exiger l'état d'avancement dès l'ouverture ferait saisir un
            // chiffre que personne ne connaît encore. Le défaut est « en
            // cours », à 0 %.
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'progressPercentage' => ['nullable', 'integer', 'between:0,100'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Le service visé doit être une PRESTATION DU CATALOGUE de la société.
     *
     * Trois filtres, aucun décoratif :
     *
     *  - `company_id` — sans lui, un identifiant deviné classerait le projet
     *    sous la prestation d'une autre société (§5.3). `Rule::exists`
     *    interroge la table SANS le global scope Eloquent : le filtre doit être
     *    écrit à la main.
     *  - `type = 'service'` — `products` porte aussi des biens ; accepter l'un
     *    d'eux classerait un chantier sous un article de quincaillerie.
     *  - `deleted_at IS NULL` — on ne classe pas sous une prestation archivée.
     *    Les projets DÉJÀ classés la conservent, eux : c'est la relation qui
     *    cesse de rendre le libellé, pas la colonne qui s'efface.
     *
     * Partagée avec `ProjectUpdateRequest` : deux copies divergeraient au
     * premier filtre ajouté, et le PATCH accepterait ce que le POST refuse.
     *
     * @return list<mixed>
     */
    public static function serviceRules(): array
    {
        return [
            'nullable',
            'uuid',
            Rule::exists('products', 'id')
                ->where('company_id', app(TenantContext::class)->requireId())
                ->where('type', ProductType::Service->value)
                ->whereNull('deleted_at'),
        ];
    }
}
