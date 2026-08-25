<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Cash\Services\PaymentCashMirror;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Payment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les règlements d'une facture.
 *
 * Concentre les trois règles qu'aucun contrôleur ne doit rejouer :
 *
 *  1. **On ne règle qu'une FACTURE ÉMISE.** Un devis propose, il n'encaisse
 *     pas ; un brouillon n'existe pas fiscalement, encaisser dessus créerait
 *     une recette rattachée à une pièce sans numéro. Une facture ANNULÉE est
 *     close : y ajouter un règlement la contredirait.
 *  2. **`documents.paid_cents` est DÉRIVÉ, jamais saisi.** Il est recalculé
 *     comme la somme des règlements vivants à chaque écriture — jamais
 *     incrémenté. Un compteur qu'on incrémente diverge au premier incident
 *     (rollback partiel, suppression concurrente) et rien ne le rattrape ; une
 *     somme se recalcule et retombe toujours juste.
 *  3. **Le statut suit le cumul**, selon `Document::settlementStatus()` : rien
 *     d'encaissé → `sent`, une partie → `partial`, la totalité → `paid`.
 *
 * Tout passe par une transaction : le règlement, le cumul et le statut forment
 * un seul fait comptable. Les écrire séparément laisserait, au moindre échec,
 * une facture dont le badge contredit son propre historique.
 */
final class PaymentWriteService
{
    public function __construct(
        private readonly PaymentScanStorage $scans,
        // Le journal de caisse porte une COPIE de chaque règlement depuis le
        // 2026-08-25. Elle s'écrit dans la transaction du règlement : une
        // facture réglée sans mouvement de caisse — ou l'inverse — ne doit
        // jamais être visible (cf. `PaymentCashMirror`).
        private readonly PaymentCashMirror $cashMirror,
    ) {}

    /**
     * Enregistre un règlement et réaligne la facture.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Document $invoice, array $data, ?UploadedFile $scan = null): Payment
    {
        $this->assertPayable($invoice);

        $method = $data['method'] instanceof PaymentMethod
            ? $data['method']
            : PaymentMethod::from((string) $data['method']);

        // Le fichier est écrit AVANT la transaction : un disque n'est pas
        // transactionnel, et un rollback laisserait de toute façon le fichier
        // derrière lui. En cas d'échec en base, c'est un orphelin sur le disque
        // — inerte, sans ligne pour le désigner — plutôt qu'une ligne qui
        // pointe vers un fichier absent, laquelle casserait l'écran.
        $stored = $scan !== null && $method->isBankInstrument()
            ? $this->scans->store($scan, $invoice->company_id)
            : null;

        return DB::transaction(function () use ($invoice, $data, $method, $stored): Payment {
            $payment = new Payment([
                'invoice_id' => $invoice->id,
                'amount_cents' => (int) $data['amount_cents'],
                'currency' => $invoice->currency,
                'paid_on' => $data['paid_on'],
                'method' => $method->value,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                // Les champs d'effet sont ignorés hors chèque et LCN : les
                // accepter poserait un numéro de chèque sur des espèces, ce que
                // la contrainte `payments_check_fields_check` refuserait — mais
                // avec une erreur SQL brute au lieu d'un silence propre.
                'check_number' => $method->isBankInstrument() ? ($data['check_number'] ?? null) : null,
                'bank_deposit_date' => $method->isBankInstrument() ? ($data['bank_deposit_date'] ?? null) : null,
                'received_date' => $method->isBankInstrument() ? ($data['received_date'] ?? null) : null,
                'check_status' => $method->isBankInstrument() ? ($data['check_status'] ?? null) : null,
                'scan_path' => $stored['path'] ?? null,
                'scan_name' => $stored['name'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $payment->save();

            $this->refreshSettlement($invoice);

            // La relation est posée à la main : `sync()` lit `$payment->invoice`
            // pour composer le libellé et retrouver le tiers, et la recharger
            // depuis la base coûterait une requête pour un objet qu'on tient.
            $payment->setRelation('invoice', $invoice);
            $this->cashMirror->sync($payment);

            return $payment->refresh();
        });
    }

    /**
     * Retire un règlement et réaligne la facture.
     *
     * SOFT DELETE : la ligne demeure avec son `deleted_at`. C'est le seul
     * moyen de dire plus tard qu'un encaissement a été saisi puis retiré —
     * après un chèque revenu impayé, par exemple. Le scan, lui, est conservé :
     * l'effacer priverait de la pièce justificative celui qui vient
     * précisément vérifier ce qui s'est passé.
     */
    public function delete(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $invoice = Document::query()->find($payment->invoice_id);

            $payment->delete();

            // Le règlement part en soft delete, sa copie en caisse s'efface
            // pour de bon : un reflet conservé avec un `deleted_at` n'apprend
            // rien à personne et compterait double à la première lecture faite
            // sans son scope.
            $this->cashMirror->forget($payment);

            if ($invoice instanceof Document) {
                $this->refreshSettlement($invoice);
            }
        });
    }

    /**
     * Recalcule `paid_cents` et le statut de règlement depuis les règlements
     * VIVANTS de la facture.
     *
     * Appelée dans la transaction de l'écriture qui la déclenche. La somme est
     * relue en base et non tenue en mémoire : c'est ce qui rend l'opération
     * idempotente, et ce qui permet de la rejouer si un jour un import ou une
     * reprise de données touche la table sans passer par ce service.
     */
    private function refreshSettlement(Document $invoice): void
    {
        /** @var int $paid */
        $paid = Payment::query()
            ->where('invoice_id', $invoice->id)
            ->sum('amount_cents');

        $invoice->paid_cents = (int) $paid;

        // Un état TERMINAL ne se recalcule pas. Une facture annulée qui
        // porterait encore des règlements antérieurs à son annulation
        // repasserait sinon en « payée », et l'annulation disparaîtrait de
        // l'écran sans que personne ne l'ait révoquée.
        if (! $invoice->status->isTerminal()) {
            $invoice->status = $invoice->settlementStatus();
        }

        $invoice->save();
    }

    /**
     * @throws ConflictHttpException
     */
    private function assertPayable(Document $invoice): void
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new ConflictHttpException(__('Only an invoice can receive a payment.'));
        }

        // Sans numéro, la facture n'existe pas fiscalement : lui rattacher une
        // recette créerait un encaissement sans pièce.
        if (! $invoice->isIssued()) {
            throw new ConflictHttpException(__('Issue the invoice before recording a payment.'));
        }

        if ($invoice->status === DocumentStatus::Cancelled) {
            throw new ConflictHttpException(__('A cancelled invoice cannot receive a payment.'));
        }
    }
}
