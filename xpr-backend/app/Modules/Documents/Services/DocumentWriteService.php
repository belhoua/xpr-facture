<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Services\DocumentNumberService;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Exceptions\DocumentNotEditable;
use App\Modules\Documents\Exceptions\InvalidStatusTransition;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentItem;
use App\Modules\Partners\Models\Partner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les documents commerciaux. Concentre les règles fiscales du §3
 * qu'aucun contrôleur ne doit rejouer :
 *
 *  1. **Un document naît TOUJOURS brouillon.** L'API ne permet pas de créer un
 *     document déjà émis. Le numéro s'attribue à l'émission, dans la même
 *     transaction — c'est la seule façon de garantir une séquence sans trou.
 *  2. **Un document émis est gelé.** Ni édition, ni suppression : la correction
 *     passe par un avoir, l'annulation par le statut `cancelled`.
 *  3. **Les totaux ne viennent jamais du client.** Ils sont recalculés depuis
 *     les lignes à chaque écriture par DocumentCalculator.
 *
 * Le `company_id` n'est jamais manipulé ici : BelongsToCompany le renseigne à
 * la création et cloisonne toutes les requêtes (§5).
 */
final class DocumentWriteService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly DocumentCalculator $calculator,
        private readonly DocumentItemBuilder $itemBuilder,
    ) {}

    /**
     * Crée un document, toujours à l'état de BROUILLON.
     *
     * Aucun paramètre ne permet d'émettre directement, et c'est délibéré :
     * l'émission attribue un numéro fiscal définitif. La rendre implicite dans
     * une création, c'est ouvrir la porte à des numéros consommés par des
     * appels de test ou des doubles soumissions.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Document
    {
        return DB::transaction(function () use ($data): Document {
            $document = new Document($this->headerColumns($data));
            $document->status = DocumentStatus::Draft;
            $document->number = null;
            $document->save();

            $this->replaceItems($document, self::itemsPayload($data));

            return $document->refresh()->load('items');
        });
    }

    /**
     * Met à jour un BROUILLON — en-tête et lignes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $document, array $data): Document
    {
        $this->assertEditable($document);

        return DB::transaction(function () use ($document, $data): Document {
            $document->fill($this->headerColumns($data, $document));
            $document->save();

            // Les lignes ne sont retouchées que si le client en a transmis :
            // un PATCH qui ne change qu'une date ne doit pas vider le document.
            if (array_key_exists('items', $data)) {
                $this->replaceItems($document, self::itemsPayload($data));
            }

            return $document->refresh()->load('items');
        });
    }

    /**
     * ÉMET le document : lui attribue son numéro et le gèle.
     *
     * C'est le point de bascule fiscal. Trois gardes, dans cet ordre :
     *  - il doit être encore brouillon (on ne renumérote pas) ;
     *  - il doit porter au moins une ligne — un document à 0 ligne consommerait
     *    un numéro de la séquence pour ne rien attester ;
     *  - la numérotation se fait DANS la transaction, sinon le verrou de ligne
     *    de `sequences` ne tient pas (DocumentNumberService le refuse d'ailleurs).
     */
    public function issue(Document $document, ?Carbon $issuedAt = null): Document
    {
        if (! $document->status->isEditable()) {
            throw new ConflictHttpException(__('This document has already been issued.'));
        }

        if ($document->items()->count() === 0) {
            throw new ConflictHttpException(__('A document must have at least one line to be issued.'));
        }

        return DB::transaction(function () use ($document, $issuedAt): Document {
            $document->issued_at = $issuedAt ?? $document->issued_at ?? Carbon::today();
            $document->due_at ??= $this->defaultDueDate($document);

            // Le millésime du numéro vient de l'EXERCICE couvrant la date
            // d'émission, pas de l'année civile : les exercices décalés existent.
            $document->number = $this->numbers->allocate($document->type, $document->issued_at);
            $document->status = DocumentStatus::Sent;
            $document->save();

            return $document->refresh()->load('items');
        });
    }

    /**
     * Change l'état d'un document déjà émis (devis accepté, facture réglée…).
     *
     * Les états `draft`, `cancelled` et `converted` sont hors de portée de cet
     * endpoint — cf. DocumentStatus::manuallyAssignableFor().
     */
    public function changeStatus(Document $document, DocumentStatus $target): Document
    {
        $current = $document->status;

        if ($current === $target) {
            return $document;
        }

        if ($current->isEditable()) {
            // Un brouillon n'a pas de numéro : le déclarer « payé » créerait une
            // créance réglée qui n'a jamais été facturée.
            throw new InvalidStatusTransition($current, $target);
        }

        if ($current->isTerminal()) {
            throw new InvalidStatusTransition($current, $target);
        }

        if (! in_array($target, DocumentStatus::manuallyAssignableFor($document->type), strict: true)) {
            throw new InvalidStatusTransition($current, $target);
        }

        $document->status = $target;
        $document->save();

        return $document->refresh()->load('items');
    }

    /**
     * Annule un document ÉMIS — seul changement d'état permis sur un document
     * immuable (§3). Un brouillon se supprime, il ne s'annule pas.
     */
    public function cancel(Document $document): Document
    {
        if ($document->status->isEditable()) {
            throw new ConflictHttpException(__('A draft cannot be cancelled — delete it instead.'));
        }

        if ($document->status === DocumentStatus::Cancelled) {
            throw new ConflictHttpException(__('This document is already cancelled.'));
        }

        if ($document->status === DocumentStatus::Converted) {
            // Le devis a produit une facture : annuler le devis laisserait la
            // facture orpheline d'une proposition qui n'existe plus. C'est la
            // FACTURE qu'il faut annuler.
            throw new ConflictHttpException(
                __('This quote has been converted — cancel the resulting invoice instead.'),
            );
        }

        $document->status = DocumentStatus::Cancelled;
        $document->save();

        return $document->refresh()->load('items');
    }

    /**
     * Supprime un BROUILLON. Soft delete, et sans risque pour la séquence : un
     * brouillon n'a jamais consommé de numéro.
     */
    public function delete(Document $document): void
    {
        $this->assertEditable($document);

        DB::transaction(function () use ($document): void {
            // Les lignes partent avec : elles n'ont aucune existence propre, et
            // la FK est en CASCADE. On les retire explicitement parce que le
            // soft delete du parent ne déclenche pas la cascade SQL.
            $document->items()->delete();
            $document->delete();
        });
    }

    /**
     * Remplace INTÉGRALEMENT les lignes, puis recalcule les totaux.
     *
     * Remplacement total et non réconciliation ligne à ligne : le document est
     * un brouillon, ses lignes n'ont aucune référence externe, et une
     * réconciliation par identifiant multiplierait les cas limites (ligne
     * déplacée, ligne dupliquée, identifiant d'une autre société) pour un gain
     * nul. Le tout dans la transaction ouverte par l'appelant.
     *
     * @param  list<array<string, mixed>>  $payload
     */
    private function replaceItems(Document $document, array $payload): void
    {
        $document->items()->delete();

        $items = $this->itemBuilder->build($document, $payload);

        foreach ($items as $item) {
            $item->save();
        }

        $this->refreshTotals($document, $items);
    }

    /**
     * Recalcule les totaux du document depuis ses lignes.
     *
     * Les montants transmis par le client ne sont JAMAIS repris : le total d'un
     * document est une conséquence de ses lignes, pas une donnée d'entrée.
     *
     * @param  list<DocumentItem>  $items
     */
    private function refreshTotals(Document $document, array $items): void
    {
        $lines = array_map(static fn (DocumentItem $item): array => [
            'subtotalCents' => $item->subtotal_cents,
            'discountCents' => $item->discount_cents,
            'taxCents' => $item->tax_cents,
            'totalCents' => $item->total_cents,
        ], $items);

        $totals = $this->calculator->totals($lines);

        $document->subtotal_cents = $totals['subtotalCents'];
        $document->discount_cents = $totals['discountCents'];
        $document->tax_cents = $totals['taxCents'];
        $document->total_cents = $totals['totalCents'];
        $document->save();
    }

    /**
     * Colonnes d'en-tête à partir du payload camelCase.
     *
     * Le tiers, quand il est choisi, écrase le nom, l'ICE et l'adresse : ce sont
     * des INSTANTANÉS légaux. La copie est délibérée — ils ne doivent plus
     * bouger une fois le document émis, même si la fiche du tiers est renommée
     * (§3). Une saisie libre reste acceptée pour un client de passage.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerColumns(array $data, ?Document $existing = null): array
    {
        $map = [
            'type' => 'type',
            'partnerId' => 'partner_id',
            'issuedAt' => 'issued_at',
            'dueAt' => 'due_at',
            'currency' => 'currency',
            'notes' => 'notes',
            'terms' => 'terms',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        if (isset($columns['currency']) && is_string($columns['currency'])) {
            $columns['currency'] = strtoupper($columns['currency']);
        }

        // Le type ne se change plus après création : muter un devis en facture
        // contournerait la numérotation et la matrice d'états.
        if ($existing !== null) {
            unset($columns['type']);
        }

        $partnerId = array_key_exists('partnerId', $data)
            ? (is_string($data['partnerId']) ? $data['partnerId'] : null)
            : $existing?->partner_id;

        $columns += $this->clientSnapshot($data, $partnerId, $existing);

        return $columns;
    }

    /**
     * Identité du client à FIGER sur le document.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clientSnapshot(array $data, ?string $partnerId, ?Document $existing): array
    {
        if ($partnerId !== null) {
            // Le scope tenant s'applique : un tiers d'une autre société est
            // introuvable, et le FormRequest l'a déjà écarté.
            $partner = Partner::query()->find($partnerId);

            if ($partner instanceof Partner) {
                return [
                    // La RAISON SOCIALE, pas l'enseigne : le document commercial
                    // engage l'entité légale.
                    'client_name' => $partner->legal_name,
                    'client_ice' => $partner->ice,
                    'client_address' => $partner->address,
                ];
            }
        }

        $name = $data['clientName'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return ['client_name' => trim($name)];
        }

        return $existing !== null ? [] : ['client_name' => ''];
    }

    /**
     * Échéance par défaut : la date d'émission plus le délai de règlement
     * convenu avec le tiers. Sans tiers répertorié, aucune échéance n'est
     * inventée — mieux vaut un champ vide qu'une date arbitraire sur laquelle
     * une relance se déclencherait.
     */
    private function defaultDueDate(Document $document): ?Carbon
    {
        if ($document->partner_id === null || $document->issued_at === null) {
            return null;
        }

        $partner = Partner::query()->find($document->partner_id);

        if (! $partner instanceof Partner) {
            return null;
        }

        return $document->issued_at->copy()->addDays($partner->payment_terms_days);
    }

    private function assertEditable(Document $document): void
    {
        if (! $document->status->isEditable()) {
            throw new DocumentNotEditable;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function itemsPayload(array $data): array
    {
        $items = $data['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_filter($items, 'is_array'));

        return $rows;
    }
}
