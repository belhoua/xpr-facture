<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Resources;

use App\Modules\Conventions\Models\Convention;
use App\Modules\Conventions\Models\FileDeposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'un dépôt de dossier.
 *
 * @mixin FileDeposit
 */
final class FileDepositResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conventionId' => $this->convention_id,
            'reference' => $this->reference,
            'depositedAt' => $this->deposited_at->toDateString(),
            'organisation' => $this->organisation,
            'status' => $this->status->value,
            'decidedAt' => $this->decided_at?->toDateString(),
            'notes' => $this->notes,

            // Contexte minimal de la convention : l'écran « Dépôts de dossier »
            // liste des dépôts de plusieurs projets, et une référence de
            // récépissé seule ne dit pas de quel chantier il s'agit. Deux
            // champs, pas la convention entière — la fiche complète a son
            // endpoint.
            //
            // La relation peut être chargée ET nulle : `Convention` porte un
            // soft delete, et le dépôt d'une convention archivée survit à sa
            // ligne visible. On rend `null` plutôt que de laisser filer une
            // erreur — le dépôt existe toujours, c'est son contexte qui a
            // disparu.
            'convention' => $this->whenLoaded('convention', function (): ?array {
                $convention = $this->convention;

                if (! $convention instanceof Convention) {
                    return null;
                }

                return [
                    'id' => $convention->id,
                    'ownerName' => $convention->owner_name,
                    'projectDescription' => $convention->project_description,
                    'dossierNumber' => $convention->dossier_number,
                ];
            }),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
