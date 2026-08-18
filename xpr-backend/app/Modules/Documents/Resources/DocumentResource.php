<?php

declare(strict_types=1);

namespace App\Modules\Documents\Resources;

use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentItem;
use App\Modules\Documents\Services\DocumentCalculator;
use App\Modules\Payments\Resources\PaymentResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Sérialisation d'un document commercial. Contrat plat en camelCase, sans
 * enveloppe `data` — convention du dépôt.
 *
 * Le RÉCAPITULATIF DE TVA PAR TAUX est calculé ici plutôt que stocké : c'est
 * une projection des lignes, et le §3 exige déjà que le taux appliqué soit figé
 * sur chacune d'elles. Le dénormaliser en base créerait une seconde source de
 * vérité à tenir synchronisée, pour un calcul qui coûte une boucle.
 *
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $items = $this->whenLoaded('items');
        $itemsLoaded = $items instanceof Collection;

        $payments = $this->whenLoaded('payments');
        $paymentsLoaded = $payments instanceof Collection;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'number' => $this->number,
            'status' => $this->status->value,

            'partnerId' => $this->partner_id,
            // Non nul UNIQUEMENT sur la réponse d'une écriture qui vient
            // d'ouvrir une fiche client depuis un nom saisi librement. Émis
            // toujours, à `null` le reste du temps : une clé qui apparaît et
            // disparaît obligerait le client à tester sa présence avant sa
            // valeur. Un tiers RETROUVÉ le laisse à null — rien à annoncer.
            'autoCreatedPartnerName' => $this->resource->autoCreatedPartnerName,
            'projectId' => $this->project_id,
            // Le titre n'est rendu QUE si la relation a été chargée : l'exposer
            // sans condition ferait une requête par ligne sur les écrans qui ne
            // l'affichent pas.
            'projectTitle' => $this->whenLoaded('project', fn (): ?string => $this->project?->title),
            'parentDocumentId' => $this->parent_document_id,
            'parentNumber' => $this->whenLoaded('parent', fn (): ?string => $this->parent?->number),

            // Identité FIGÉE à l'émission : elle ne suit pas un renommage du tiers.
            'clientName' => $this->client_name,
            'clientIce' => $this->client_ice,
            'clientAddress' => $this->client_address,

            // Objet libre. Porte seul le sens d'une situation, qui n'a pas de
            // lignes de détail pour l'exprimer.
            'subject' => $this->subject,

            // Ville d'établissement, saisie par document : un bureau de
            // contrôle établit ses devis là où se trouve le chantier.
            'issueCity' => $this->issue_city,

            'issuedAt' => $this->issued_at?->toDateString(),
            'dueAt' => $this->due_at?->toDateString(),

            'subtotalCents' => $this->subtotal_cents,
            'discountCents' => $this->discount_cents,
            'taxCents' => $this->tax_cents,
            'totalCents' => $this->total_cents,
            // Cumul encaissé, tel que porté par le document : recalculé depuis
            // `payments` à chaque écriture de règlement sur une facture
            // (PaymentWriteService::refreshSettlement), saisi en en-tête sur
            // une situation. `remainingCents` en dérive plutôt que d'être
            // stocké — une seconde source de vérité que rien ne garantirait
            // synchronisée (cf. Document::remainingCents()).
            'paidCents' => $this->paid_cents,
            'remainingCents' => $this->remainingCents(),
            'currency' => $this->currency,

            'notes' => $this->notes,
            'terms' => $this->terms,

            // Historique d'encaissement, quand il a été chargé. `whenLoaded`
            // et non un chargement à la demande : une resource qui déclenche
            // ses propres requêtes rend le N+1 invisible depuis le contrôleur.
            'payments' => $paymentsLoaded
                ? PaymentResource::collection($payments)->resolve()
                : $this->whenLoaded('payments'),

            'items' => $itemsLoaded
                ? DocumentItemResource::collection($items)->resolve()
                : $this->whenLoaded('items'),
            'taxSummary' => $itemsLoaded ? $this->taxSummary($items) : $this->whenLoaded('items'),

            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Ventilation de la TVA par taux appliqué — mention obligatoire en pied de
     * document (§3).
     *
     * @param  Collection<int, DocumentItem>  $items
     * @return list<array{rate: string, baseCents: int, taxCents: int}>
     */
    private function taxSummary(Collection $items): array
    {
        /** @var list<array{taxRate: string, subtotalCents: int, taxCents: int}> $lines */
        $lines = array_values($items
            ->map(static fn (DocumentItem $item): array => [
                'taxRate' => (string) $item->tax_rate,
                'subtotalCents' => $item->subtotal_cents,
                'taxCents' => $item->tax_cents,
            ])
            ->all());

        return app(DocumentCalculator::class)->taxSummary($lines);
    }
}
