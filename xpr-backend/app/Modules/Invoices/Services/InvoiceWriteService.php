<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Modules\Invoices\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les factures. Concentre les DEUX règles fiscales non
 * négociables de la charte (§3) que le contrôleur ne doit jamais rejouer :
 *
 *  1. Numérotation continue, sans trou, attribuée UNIQUEMENT à la validation
 *     (passage hors brouillon). Le format `FAC-{YYYY}-{0000}` est sérialisé
 *     par un verrou pessimiste pour résister à la concurrence.
 *  2. Immuabilité : une facture validée ne se modifie ni ne se supprime — la
 *     correction se fait par annulation (statut `cancelled`), jamais un DELETE.
 *
 * Le `company_id` n'est jamais manipulé ici : le trait BelongsToCompany le
 * renseigne à la création et cloisonne toutes les requêtes (§5).
 */
final class InvoiceWriteService
{
    /**
     * @param  array{clientName: string, issuedAt: ?string, dueAt: ?string, status: string, totalCents: int, currency: string}  $data
     */
    public function create(array $data): Invoice
    {
        return DB::transaction(function () use ($data): Invoice {
            $invoice = new Invoice($this->toColumns($data));

            // Créer directement en « envoyée » (ou tout autre statut validé),
            // c'est valider : on attribue le numéro dans la même transaction
            // que l'insertion pour ne jamais laisser un trou dans la séquence.
            if ($invoice->status !== 'draft') {
                $this->assignNumber($invoice);
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * @param  array{clientName: string, issuedAt: ?string, dueAt: ?string, status: string, totalCents: int, currency: string}  $data
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        $this->assertEditable($invoice);

        return DB::transaction(function () use ($invoice, $data): Invoice {
            $invoice->fill($this->toColumns($data));

            // Un brouillon que l'on fait basculer en statut validé se voit
            // attribuer son numéro à cet instant précis, pas avant.
            if ($invoice->status !== 'draft' && $invoice->number === null) {
                $this->assignNumber($invoice);
            }

            $invoice->save();

            return $invoice;
        });
    }

    public function delete(Invoice $invoice): void
    {
        $this->assertEditable($invoice);

        // Soft delete : un brouillon jamais numéroté ne laisse aucun trou.
        $invoice->delete();
    }

    /**
     * Annulation d'une facture validée : seul changement d'état autorisé sur
     * un document immuable (§3). Un brouillon se supprime, il ne s'annule pas ;
     * une facture déjà annulée ne se réannule pas.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        if ($invoice->status === 'draft') {
            throw new ConflictHttpException(
                __('A draft cannot be cancelled — delete it instead.'),
            );
        }

        if ($invoice->status === 'cancelled') {
            throw new ConflictHttpException(__('This invoice is already cancelled.'));
        }

        $invoice->status = 'cancelled';
        $invoice->save();

        return $invoice;
    }

    /**
     * Lève 409 si la facture n'est plus un brouillon : elle est validée, donc
     * gelée. Le front en tient déjà compte (actions masquées), mais l'API reste
     * l'autorité — une requête forgée ne doit pas passer (§10).
     */
    private function assertEditable(Invoice $invoice): void
    {
        if ($invoice->status !== 'draft') {
            throw new ConflictHttpException(
                __('This invoice is validated and can no longer be modified or deleted.'),
            );
        }
    }

    /**
     * Attribue le prochain numéro de l'exercice pour la société courante.
     *
     * `lockForUpdate()` verrouille les factures déjà numérotées de l'année le
     * temps de la transaction : deux validations simultanées s'exécutent alors
     * en série et ne peuvent pas réclamer le même numéro. Le scope tenant
     * restreint déjà la requête à la société active.
     */
    private function assignNumber(Invoice $invoice): void
    {
        $year = ($invoice->issued_at ?? Carbon::now())->year;
        $prefix = "FAC-{$year}-";

        $lastNumber = Invoice::query()
            ->where('number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('number')
            ->value('number');

        $lastSequence = is_string($lastNumber)
            ? (int) substr($lastNumber, strlen($prefix))
            : 0;

        $invoice->number = $prefix.str_pad((string) ($lastSequence + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Traduit le payload camelCase de l'API vers les colonnes snake_case.
     *
     * @param  array{clientName: string, issuedAt: ?string, dueAt: ?string, status: string, totalCents: int, currency: string}  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        return [
            'client_name' => $data['clientName'],
            'issued_at' => $data['issuedAt'],
            'due_at' => $data['dueAt'],
            'status' => $data['status'],
            'total_cents' => $data['totalCents'],
            'currency' => strtoupper($data['currency']),
        ];
    }
}
