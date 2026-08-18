<?php

declare(strict_types=1);

namespace App\Modules\Projects\Resources;

use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Services\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Sérialisation d'un projet. Contrat plat en camelCase, sans enveloppe `data` —
 * convention du dépôt.
 *
 * @mixin Project
 */
final class ProjectResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $deliverables = $this->whenLoaded('deliverables');
        $deliverablesLoaded = $deliverables instanceof Collection;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'progressPercentage' => $this->progress_percentage,
            'description' => $this->description,

            'partnerId' => $this->partner_id,
            // Nom du client rendu à plat : la liste l'affiche sur chaque ligne,
            // et l'obliger à recouper une seconde requête pour un libellé
            // serait payer une jointure sans en tirer parti.
            //
            // La relation peut être chargée ET nulle : `Partner` porte un soft
            // delete, et un projet survit à l'archivage de son client. On rend
            // `null` plutôt que de laisser filer une erreur d'accès sur null.
            'clientName' => $this->whenLoaded(
                'partner',
                fn (): ?string => $this->partner instanceof Partner
                    ? $this->partner->legal_name
                    : null,
            ),

            'serviceId' => $this->service_id,
            // Même raison que `clientName` : le nom est rendu à plat pour la
            // colonne SERVICE de la liste. `null` couvre DEUX cas que l'écran
            // affiche de la même façon — le projet non classé, et le service
            // archivé depuis, `Service` portant un soft delete.
            'serviceName' => $this->whenLoaded(
                'service',
                fn (): ?string => $this->service instanceof Service
                    ? $this->service->name
                    : null,
            ),

            'deliverables' => $deliverablesLoaded
                ? DeliverableResource::collection($deliverables)->resolve()
                : $this->whenLoaded('deliverables'),
            // Compté ICI et non par l'écran : la liste n'affiche que le nombre
            // de remises, et deux clients (web, mobile) écriraient chacun leur
            // façon de le tirer d'un tableau qu'ils n'ont pas toujours reçu.
            'deliverableCount' => $deliverablesLoaded
                ? $deliverables->count()
                : $this->whenLoaded('deliverables'),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
