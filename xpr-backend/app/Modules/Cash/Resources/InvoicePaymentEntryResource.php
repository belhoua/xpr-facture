<?php

declare(strict_types=1);

namespace App\Modules\Cash\Resources;

use App\Modules\Documents\Models\Document;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un RÈGLEMENT DE FACTURE vu comme une ligne du journal de caisse.
 *
 * ── Pourquoi une seconde ressource et non une colonne de plus ─────────────
 *
 * Le journal de caisse a deux sources qui ne se rejoignent qu'à l'affichage :
 * `cash_movements`, saisi à la main, et `payments`, dérivé d'une facture. Elles
 * partagent la FORME de la ligne — date, tiers, montant, mode — mais rien de
 * leur cycle de vie : un mouvement se corrige librement, un règlement ne se
 * touche que depuis sa facture, où il réaligne `paid_cents` et le statut.
 *
 * Cette ressource projette donc un `Payment` dans la forme commune, et le champ
 * `source` dit d'où vient la ligne. L'écran s'en sert pour ne PAS proposer
 * « modifier » et « supprimer » sur un règlement : les deux mèneraient à un 404
 * (l'identifiant n'est pas celui d'un mouvement) et, s'ils aboutissaient,
 * laisseraient une facture dont le cumul contredit la caisse.
 *
 * ── Les deux champs volontairement nuls ───────────────────────────────────
 *
 * `registerName` : un règlement n'entre dans aucune caisse physique — un
 * virement arrive en banque. Inventer « Caisse principale » ferait entrer un
 * virement de 7 000 MAD dans un tiroir-caisse, ce qu'aucun rapprochement ne
 * retrouverait ensuite.
 *
 * `clientName` / `partnerId` : lus sur la FACTURE, jamais sur un tiers
 * rattaché. `documents.client_name` porte le nom FIGÉ à l'émission (§3) ; c'est
 * lui qui figure sur la pièce, et donc lui qui doit figurer en face de
 * l'encaissement. La facture peut malgré tout être absente de la relation —
 * `Document` porte un soft delete —, d'où le repli sur `null`.
 *
 * @mixin Payment
 */
final class InvoicePaymentEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $invoice = $this->invoice instanceof Document ? $this->invoice : null;

        return [
            'id' => $this->id,
            'source' => 'payment',

            'partnerId' => $invoice?->partner_id,
            'clientName' => $invoice?->client_name,

            'occurredAt' => $this->paid_on->toDateString(),
            // Composé côté serveur, comme les messages d'erreur du module : le
            // libellé est une donnée DÉRIVÉE de la pièce, pas un texte
            // d'interface. `SetLocale` le rend dans la langue du compte.
            'label' => __('Payment of invoice :number', [
                'number' => $invoice->number ?? '—',
            ]),
            'method' => $this->method->value,
            'registerName' => null,
            // TOUJOURS positif : `payments_amount_positive_check` l'impose en
            // base. Un règlement est un encaissement, un remboursement relève
            // d'un avoir.
            'amountCents' => $this->amount_cents,
            'currency' => $this->currency,

            // De quoi renvoyer l'utilisateur vers la pièce d'origine, seul
            // endroit où ce règlement se corrige.
            'invoiceId' => $this->invoice_id,
            'invoiceNumber' => $invoice?->number,
        ];
    }
}
