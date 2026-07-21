<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Services\DocumentNumberService;
use App\Modules\Invoices\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les factures. Concentre les DEUX règles fiscales non
 * négociables de la charte (§3) que le contrôleur ne doit jamais rejouer :
 *
 *  1. Numérotation continue, sans trou, attribuée UNIQUEMENT à la validation
 *     (passage hors brouillon). Elle est déléguée à DocumentNumberService, qui
 *     verrouille la ligne de séquence de l'exercice.
 *  2. Immuabilité : une facture validée ne se modifie ni ne se supprime — la
 *     correction se fait par annulation (statut `cancelled`), jamais un DELETE.
 *
 * Le `company_id` n'est jamais manipulé ici : le trait BelongsToCompany le
 * renseigne à la création et cloisonne toutes les requêtes (§5).
 */
final class InvoiceWriteService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

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
     * Attribue le prochain numéro de l'exercice couvrant la date d'émission.
     *
     * Déléguée à DocumentNumberService : le compteur vit dans `sequences` et
     * son verrou de ligne tient même quand aucune facture n'existe encore.
     * L'ancienne approche — MAX(number) + 1 sous lockForUpdate — avait deux
     * défauts que ce remplacement corrige : `lockForUpdate()` sur une requête
     * sans résultat ne verrouille rien, donc deux premières factures
     * concurrentes prenaient toutes deux 0001 ; et un numéro libéré par une
     * suppression était réattribué, ce que §3 interdit.
     *
     * Appelée depuis les transactions ouvertes par create() et update().
     */
    private function assignNumber(Invoice $invoice): void
    {
        $invoice->number = $this->numbers->allocate(
            DocumentType::Invoice,
            $invoice->issued_at ?? Carbon::today(),
        );
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
