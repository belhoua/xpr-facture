<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Resources;

use App\Modules\Conventions\Models\Convention;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Sérialisation d'une convention. Contrat plat en camelCase, sans enveloppe
 * `data` : les contrôleurs renvoient `->resolve()`, comme le reste du dépôt.
 *
 * @mixin Convention
 */
final class ConventionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $instalments = $this->instalments();

        return [
            'id' => $this->id,
            'sourceDocumentId' => $this->source_document_id,
            // Numéro du devis / de la facture d'origine, pour l'afficher sans
            // seconde requête. `whenLoaded` : la liste ne charge pas la
            // relation, ce serait une jointure pour une colonne.
            'sourceDocumentNumber' => $this->whenLoaded(
                'sourceDocument',
                fn (): ?string => $this->sourceDocument?->number,
            ),
            'partnerId' => $this->partner_id,

            'dossierNumber' => $this->dossier_number,
            'status' => $this->status->value,
            'issueCity' => $this->issue_city,
            'issuedAt' => $this->issued_at?->toDateString(),

            'ownerName' => $this->owner_name,
            'ownerIce' => $this->owner_ice,
            'ownerRc' => $this->owner_rc,
            'ownerAddress' => $this->owner_address,

            'projectDescription' => $this->project_description,
            'projectAddress' => $this->project_address,
            'projectTitleDeed' => $this->project_title_deed,

            'lots' => $this->lots,
            'executionDelay' => $this->execution_delay,

            'totalCents' => $this->total_cents,
            'currency' => $this->currency,
            'advancePercent' => $this->advance_percent,
            'visaPercent' => $this->visa_percent,
            'completionPercent' => $this->completion_percent,

            // Échéancier en CENTIMES, calculé par le serveur. Le client pourrait
            // le refaire depuis les pourcentages, mais alors deux
            // implémentations de l'arrondi cohabiteraient — et c'est le contrat
            // imprimé qui afficherait l'écart d'un centime.
            'instalmentsCents' => [
                'advance' => $instalments['advance'],
                'visa' => $instalments['visa'],
                'completion' => $instalments['completion'],
            ],

            'notes' => $this->notes,

            'deposits' => FileDepositResource::collection(
                $this->whenLoaded('deposits'),
            ),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
