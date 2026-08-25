<?php

declare(strict_types=1);

namespace App\Modules\Cash\Resources;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Partners\Models\Partner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Une ligne du journal de caisse, saisie à la main OU copiée d'un règlement.
 *
 * Depuis le miroir (2026-08-25), les deux vivent dans la même table et passent
 * donc par cette seule resource — `InvoicePaymentEntryResource` ne projette
 * plus rien pour le journal.
 *
 * Le champ `source` continue de les distinguer, et il ne change pas de sens :
 * c'est lui qui autorise l'écran à proposer « modifier » et « supprimer » sur
 * une écriture saisie, et à les refuser sur un règlement, qui ne se corrige que
 * depuis sa facture. Il se lit désormais sur `payment_id` plutôt que sur la
 * table d'origine — même information, une jointure de moins.
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
            'source' => $this->isMirroredPayment() ? 'payment' : 'cash',

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
            // Nature de la dépense. Nulle sur un encaissement : une entrée
            // d'argent n'est pas une charge, et le service la vide à
            // l'écriture plutôt que de laisser une valeur résiduelle traîner.
            'charge' => $this->charge,
            'method' => $this->method,
            'registerName' => $this->register_name,
            'amountCents' => $this->amount_cents,
            'currency' => $this->currency,

            // La pièce d'origine, sur un mouvement copié d'un règlement : c'est
            // le seul endroit où celui-ci se corrige, et l'écran y renvoie.
            // Nuls sur une écriture saisie, qui ne découle d'aucune pièce — les
            // clés sont émises dans les deux cas pour que la ligne garde une
            // forme unique côté client, sans branche sur les clés présentes.
            'invoiceId' => $this->payment?->invoice_id,
            'invoiceNumber' => $this->payment?->invoice?->number,
        ];
    }
}
