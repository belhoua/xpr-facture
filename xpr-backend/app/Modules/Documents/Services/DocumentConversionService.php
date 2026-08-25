<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Services\DocumentNumberService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Transformations d'un document en un autre : **devis → facture**.
 *
 * C'est le bénéfice concret de la table unique : l'opération est une COPIE DE
 * LIGNES au sein d'un même schéma, pas une traduction entre deux modèles.
 * Aucune règle de TVA n'est réécrite — les montants figés sur les lignes
 * d'origine sont repris tels quels.
 *
 * Le document produit était un BROUILLON jusqu'au 2026-08-14 : la conversion
 * proposait, elle n'émettait pas. Depuis que la facture se numérote à la
 * création, la facture issue d'un devis est NUMÉROTÉE elle aussi. Ce n'est pas
 * une décision distincte, c'est la conséquence nécessaire de la précédente :
 * l'écran n'offre plus d'action « émettre », et une facture née brouillon y
 * serait restée bloquée, sans aucun chemin vers son numéro.
 */
final class DocumentConversionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
    ) {}

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
     * Copie l'en-tête et les lignes d'un document source vers un nouveau
     * document du type demandé.
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
            // Le PROJET suit la pièce, au même titre que le tiers.
            //
            // Il ne suivait pas jusqu'au 2026-08-24 : une facture née d'un
            // devis de chantier repartait sans rattachement. Elle disparaissait
            // alors du filtre « chantier » de l'écran « situations par client »
            // — et de ses quatre indicateurs, qui se calculent sur ce même
            // filtre. Le devis restait rattaché, la facture non : le chantier
            // affichait un montant proposé sans jamais montrer le facturé.
            //
            // La cohérence client → projet, elle, n'a pas à être revérifiée
            // ici : le couple (partner_id, project_id) est repris tel quel
            // d'un document que `DocumentWriteService` a déjà validé.
            'project_id' => $source->project_id,
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

        // Les copies partent en UNE requête : convertir un devis de trente
        // postes tenait trente INSERT dans la transaction qui numérote la
        // facture (cf. DocumentItem::insertMany).
        $copies = [];

        foreach ($source->items()->get() as $item) {
            $copy = $item->replicate(['document_id', 'created_at', 'updated_at']);
            $copy->document_id = $target->id;
            $copies[] = $copy;
        }

        DocumentItem::insertMany($copies);

        // Numérotation APRÈS la copie des lignes, jamais avant : un numéro posé
        // sur un document encore vide serait consommé sans rien attester, et le
        // rollback de la transaction ne le rendrait pas à la séquence.
        //
        // L'appelant a déjà ouvert la transaction (DocumentNumberService refuse
        // d'opérer hors transaction) et `issued_at` est posée par les overrides.
        if ($type->numbersOnCreate()) {
            $target->number = $this->numbers->allocate($type, $target->issued_at ?? Carbon::today());
            $target->status = $target->settlementStatus();
            $target->save();
        }

        return $target->refresh()->load('items');
    }
}
