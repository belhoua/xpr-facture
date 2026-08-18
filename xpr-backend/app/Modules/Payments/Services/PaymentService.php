<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Documents\Models\Document;
use App\Modules\Payments\Models\Payment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lectures du module Règlements.
 *
 * Toutes les résolutions passent par ici, et TOUTES sous le scope tenant : un
 * identifiant venu de l'URL ne doit jamais être résolu autrement (§5). Les
 * contrôleurs reçoivent donc des `string` et appellent ce service — c'est ce
 * que verrouille `tests/Feature/Tenancy/RouteBindingScopeTest.php`.
 */
final class PaymentService
{
    /**
     * Facture visée par un identifiant d'URL.
     *
     * @throws ModelNotFoundException 404 et non 403 : l'existence même d'une
     *                                facture d'une autre société ne doit pas
     *                                fuiter.
     */
    public function findInvoiceForCompany(string $id): Document
    {
        $invoice = Document::query()->find($id);

        if (! $invoice instanceof Document) {
            throw (new ModelNotFoundException)->setModel(Document::class, [$id]);
        }

        return $invoice;
    }

    /** @throws ModelNotFoundException */
    public function findForCompany(string $id): Payment
    {
        $payment = Payment::query()->find($id);

        if (! $payment instanceof Payment) {
            throw (new ModelNotFoundException)->setModel(Payment::class, [$id]);
        }

        return $payment;
    }

    /**
     * Historique d'une facture, du plus RÉCENT au plus ancien.
     *
     * `created_at` départage les règlements du même jour : deux acomptes reçus
     * le même jour doivent tout de même s'afficher dans l'ordre où ils ont été
     * saisis, sans quoi la liste change d'ordre à chaque rechargement.
     *
     * @return Collection<int, Payment>
     */
    public function historyFor(Document $invoice): Collection
    {
        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->orderByDesc('paid_on')
            ->orderByDesc('created_at')
            ->get();
    }
}
