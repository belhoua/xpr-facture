<?php

declare(strict_types=1);

namespace App\Modules\Cash\Resources;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Partners\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une écriture SAISIE du journal de caisse.
 *
 * Partage sa forme avec InvoicePaymentEntryResource, qui projette un règlement
 * de facture dans le même moule — les deux alimentent la même liste. Le champ
 * `source` les distingue : c'est lui qui autorise l'écran à proposer
 * « modifier » et « supprimer » ici, et à les refuser sur un règlement, qui ne
 * se corrige que depuis sa facture.
 *
 * @mixin CashMovement
 */
final class CashMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => 'cash',

            'partnerId' => $this->partner_id,
            // Nom du tiers rendu à plat : l'écran l'affiche sur chaque ligne du
            // journal, et l'obliger à recouper une seconde requête pour un
            // libellé serait payer une jointure sans en tirer parti.
            //
            // `null` couvre DEUX cas que l'écran distingue à sa façon : le
            // mouvement sans tiers (un loyer, des fournitures) et le tiers
            // archivé — `Partner` porte un soft delete, l'écriture de caisse
            // lui survit.
            'clientName' => $this->partner instanceof Partner
                ? $this->partner->legal_name
                : null,

            'occurredAt' => $this->occurred_at->toDateString(),
            'label' => $this->label,
            'method' => $this->method,
            'registerName' => $this->register_name,
            'amountCents' => $this->amount_cents,
            'currency' => $this->currency,

            // Nuls par construction : une écriture saisie ne découle d'aucune
            // pièce. Les champs sont émis quand même pour que les deux sources
            // rendent EXACTEMENT la même forme — un schéma unique côté client,
            // sans branche sur les clés présentes.
            'invoiceId' => null,
            'invoiceNumber' => null,
        ];
    }
}
