<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Transformations d'un document en un autre : **devis → facture** et
 * **facture → avoir**.
 *
 * C'est le bénéfice concret de la table unique : les deux opérations sont une
 * COPIE DE LIGNES au sein d'un même schéma, pas une traduction entre deux
 * modèles. Aucune règle de TVA n'est réécrite — les montants figés sur les
 * lignes d'origine sont repris tels quels.
 *
 * Le document produit est toujours un BROUILLON : la conversion propose, elle
 * n'émet pas. L'utilisateur relit, ajuste éventuellement, puis émet — ce qui
 * consomme alors un numéro de la séquence du type CIBLE.
 */
final class DocumentConversionService
{
    /**
     * Devis → facture.
     *
     * Le devis passe à `converted`, état terminal : il a produit son effet et
     * ne doit plus pouvoir être accepté, refusé, ni reconverti. Le lien
     * `parent_document_id` conserve la traçabilité commerciale — « d'où vient
     * cette facture » est une question qu'on pose en litige.
     */
    public function quoteToInvoice(Document $quote): Document
    {
        if ($quote->type !== DocumentType::Quote) {
            throw new ConflictHttpException(__('Only a quote can be converted into an invoice.'));
        }

        // Un devis encore brouillon n'a pas été envoyé au client : le facturer
        // reviendrait à réclamer un paiement sur une proposition jamais faite.
        if (! $quote->isIssued()) {
            throw new ConflictHttpException(__('Issue the quote before converting it.'));
        }

        if ($quote->status === DocumentStatus::Converted) {
            throw new ConflictHttpException(__('This quote has already been converted.'));
        }

        if ($quote->status->isTerminal()) {
            throw new ConflictHttpException(__('A refused or cancelled quote cannot be converted.'));
        }

        return DB::transaction(function () use ($quote): Document {
            $invoice = $this->copyInto($quote, DocumentType::Invoice, [
                // Les dates repartent de zéro : l'échéance de paiement se
                // compte depuis la facturation, pas depuis la proposition.
                'issued_at' => Carbon::today(),
                'due_at' => null,
                'terms' => $quote->terms,
            ]);

            $quote->status = DocumentStatus::Converted;
            $quote->save();

            return $invoice;
        });
    }

    /**
     * Facture → avoir (note de crédit).
     *
     * L'avoir porte des montants POSITIFS : son sens comptable vient de son
     * `type`, pas d'un signe négatif. Stocker des montants négatifs obligerait
     * chaque agrégat du produit — dashboard, états, déclaration de TVA — à
     * connaître la convention de signe, et un seul oubli inverserait un KPI.
     *
     * La facture d'origine n'est PAS modifiée : elle est immuable (§3). C'est
     * l'avoir qui vient s'imputer dessus, et le lien de parenté qui les relie.
     * L'annulation totale d'une facture reste, elle, le statut `cancelled`
     * accompagné d'un avoir de la totalité.
     */
    public function invoiceToCreditNote(Document $invoice): Document
    {
        if ($invoice->type !== DocumentType::Invoice) {
            throw new ConflictHttpException(__('A credit note can only be issued against an invoice.'));
        }

        // Sans numéro, la facture n'a jamais existé fiscalement : il n'y a rien
        // à corriger. Le brouillon se modifie ou se supprime directement.
        if (! $invoice->isIssued()) {
            throw new ConflictHttpException(__('Only an issued invoice can be credited.'));
        }

        return DB::transaction(fn (): Document => $this->copyInto($invoice, DocumentType::CreditNote, [
            'issued_at' => Carbon::today(),
            'due_at' => null,
            // Le rattachement est écrit noir sur blanc dans le document : la
            // mention « avoir sur facture n° X » est une obligation de forme,
            // et elle doit survivre même si la relation était un jour rompue.
            'notes' => __('Credit note for invoice :number', ['number' => (string) $invoice->number]),
        ]));
    }

    /**
     * Copie l'en-tête et les lignes d'un document source vers un nouveau
     * document du type demandé, à l'état de brouillon et sans numéro.
     *
     * Les lignes sont dupliquées AVEC leurs montants déjà calculés, pas
     * recalculées : le taux de TVA de la ligne source est un instantané légal
     * (§3). Le recalculer appliquerait le taux d'aujourd'hui à un document
     * d'hier — exactement ce que l'instantané sert à empêcher.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function copyInto(Document $source, DocumentType $type, array $overrides): Document
    {
        $target = new Document([
            'type' => $type->value,
            'partner_id' => $source->partner_id,
            'parent_document_id' => $source->id,
            'client_name' => $source->client_name,
            'client_ice' => $source->client_ice,
            'client_address' => $source->client_address,
            // L'OBJET et la VILLE d'établissement suivent la pièce : tous deux
            // s'impriment sur le document produit (« Objet : … », « RABAT, le
            // … »), et une facture qui perd l'objet du devis qu'elle facture
            // oblige à ressaisir ce que l'on vient de recopier.
            'subject' => $source->subject,
            'issue_city' => $source->issue_city,
            'currency' => $source->currency,
            'notes' => $source->notes,
            'terms' => $source->terms,
            // Totaux repris à l'identique : les lignes copiées sont les mêmes,
            // leur somme l'est donc aussi. Les recalculer ici ne ferait que
            // rouvrir la possibilité d'un écart entre le père et le fils.
            'subtotal_cents' => $source->subtotal_cents,
            'discount_cents' => $source->discount_cents,
            'tax_cents' => $source->tax_cents,
            'total_cents' => $source->total_cents,
            ...$overrides,
        ]);

        $target->status = DocumentStatus::Draft;
        $target->number = null;
        $target->save();

        foreach ($source->items()->get() as $item) {
            $copy = $item->replicate(['document_id', 'created_at', 'updated_at']);
            $copy->document_id = $target->id;
            $copy->save();
        }

        return $target->refresh()->load('items');
    }
}
