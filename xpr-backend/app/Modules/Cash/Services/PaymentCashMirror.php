<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Payments\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Tient à jour la COPIE d'un règlement de facture dans le journal de caisse.
 *
 * ── Pourquoi ce service existe (2026-08-25) ───────────────────────────────
 *
 * Sur demande expresse de l'exploitant. Jusqu'ici, `CashSummaryService`
 * FUSIONNAIT les deux tables en lecture : le règlement apparaissait en caisse
 * sans y être écrit, et ne pouvait donc pas contredire la facture. Le modèle
 * retenu désormais est celui d'un miroir : chaque règlement écrit sa ligne.
 *
 * Ce que le miroir coûte, et qui n'existait pas avant : deux copies d'un même
 * fait peuvent DIVERGER. Toute écriture qui contournerait ce service — un
 * import, une reprise de données, un `Payment::create()` appelé ailleurs —
 * laisserait la caisse en retard sur les factures, silencieusement.
 *
 * Trois choix limitent cette dérive :
 *
 *  1. **`sync()` est IDEMPOTENTE.** Elle ne crée pas, elle aligne : appelée
 *     deux fois sur le même règlement, elle produit le même unique mouvement.
 *     C'est ce qui permet de la rejouer sur tout le stock (cf.
 *     `xpr:backfill-cash-mirror`) sans redouter les doublons ;
 *  2. l'index `cash_movements_payment_unique` fait respecter la règle EN BASE,
 *     et non seulement ici. Une écriture concurrente est refusée par
 *     PostgreSQL, pas rattrapée après coup ;
 *  3. le mouvement produit est en LECTURE SEULE dans l'application
 *     (`CashMovementWriteService`) : personne ne peut corriger la copie sans
 *     corriger l'original.
 *
 * ── Appelé DANS la transaction du règlement ───────────────────────────────
 *
 * Volontairement, et c'est la garantie principale : un règlement enregistré
 * sans son mouvement — ou l'inverse — n'est jamais visible. Si la copie échoue,
 * le règlement est annulé avec elle.
 */
final class PaymentCashMirror
{
    /**
     * Aligne le mouvement de caisse sur le règlement.
     *
     * `updateOrCreate` sur `payment_id` : la création et la correction suivent
     * le même chemin, il n'y a donc pas deux façons de composer la ligne qui
     * pourraient diverger. Le jour où un règlement deviendra modifiable — il ne
     * l'est pas aujourd'hui, l'API n'expose ni PUT ni PATCH — cette méthode
     * couvrira le cas sans qu'on ait à y revenir.
     */
    public function sync(Payment $payment): CashMovement
    {
        $invoice = $payment->invoice;

        /** @var CashMovement $movement */
        $movement = CashMovement::query()->updateOrCreate(
            ['payment_id' => $payment->id],
            [
                // Le TIERS vient de la facture réglée. Il peut être nul : une
                // facture au nom libre n'a pas de fiche derrière elle.
                'partner_id' => $invoice?->partner_id,
                // La date du RÈGLEMENT, jamais celle du jour : un chèque
                // encaissé le 3 et saisi le 12 est un mouvement du 3.
                'occurred_at' => $payment->paid_on,
                'label' => $this->labelFor($payment),
                'method' => $payment->method->value,
                // Aucune caisse physique : un virement n'entre pas au comptoir.
                // La colonne est nullable depuis la migration du miroir.
                'register_name' => null,
                // Un règlement est toujours POSITIF
                // (`payments_amount_positive_check`), donc toujours un
                // encaissement. Le signe n'a pas à être calculé.
                'amount_cents' => $payment->amount_cents,
                'currency' => $payment->currency,
            ],
        );

        return $movement;
    }

    /**
     * Retire la copie d'un règlement supprimé.
     *
     * Suppression DURE de la copie, alors que le règlement, lui, part en soft
     * delete. Les deux ne jouent pas le même rôle : le règlement conserve la
     * trace d'un encaissement saisi puis retiré — un chèque revenu impayé — là
     * où le mouvement n'est qu'un reflet. Un reflet qu'on garderait en base
     * avec un `deleted_at` n'apporterait rien et compterait double le jour où
     * quelqu'un lirait la table sans son scope.
     */
    public function forget(Payment $payment): void
    {
        CashMovement::query()->where('payment_id', $payment->id)->delete();
    }

    /**
     * Rejoue la synchronisation sur TOUS les règlements vivants de la société
     * active, et retire les miroirs devenus orphelins.
     *
     * Sert à la reprise de données et à la réparation. Idempotente par
     * construction : elle aligne un état, elle ne l'incrémente pas.
     *
     * @return array{synced: int, removed: int}
     */
    public function rebuild(): array
    {
        return DB::transaction(function (): array {
            $payments = Payment::query()->with('invoice')->get();

            foreach ($payments as $payment) {
                $this->sync($payment);
            }

            // Les miroirs dont le règlement a été supprimé entre-temps — ou
            // dont la suppression est passée à côté du service. Le scope de
            // soft delete de `Payment` les rend invisibles à la requête
            // ci-dessus, donc absents de `$ids` : ils sont retirés ici.
            $ids = $payments->pluck('id')->all();

            $removed = CashMovement::query()
                ->whereNotNull('payment_id')
                ->when($ids !== [], fn ($query) => $query->whereNotIn('payment_id', $ids))
                ->delete();

            return ['synced' => $payments->count(), 'removed' => $removed];
        });
    }

    /**
     * « Règlement facture FAC-2026-0001 ».
     *
     * Même clé que `InvoicePaymentEntryResource`, qui composait ce libellé à la
     * lecture : deux formulations pour une même ligne se seraient séparées au
     * premier remaniement.
     *
     * ── Ce que la copie change, et qu'il faut savoir ──────────────────────
     *
     * Le libellé est désormais FIGÉ À L'ÉCRITURE, dans la langue du compte qui
     * enregistre le règlement. Composé à la lecture, il suivait la langue du
     * lecteur ; un règlement saisi par un compte français restera donc en
     * français pour un lecteur arabe. C'est le prix de la duplication, pas un
     * oubli : une donnée écrite ne se traduit plus.
     *
     * Le numéro vient de la facture ; un brouillon n'en a pas — il ne devrait
     * pas être réglé, `assertPayable()` s'y oppose, mais le libellé ne doit pas
     * pour autant se composer avec un trou au milieu.
     */
    private function labelFor(Payment $payment): string
    {
        return __('Payment of invoice :number', [
            'number' => $payment->invoice->number ?? '—',
        ]);
    }
}
