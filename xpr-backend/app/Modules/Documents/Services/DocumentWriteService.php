<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
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
 *
 * Les règles 1 et 2 souffrent UNE exception, la situation, et elle est portée
 * par l'enum (`numbersOnCreate()`, `freezesOnIssue()`) plutôt que par un
 * paramètre d'appel : aucun contrôleur ne peut la réclamer pour un autre type.
 * Justification dans `docs/modules/situations.md`.
 *
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

            // Les types à montant global (situation) n'ont pas de lignes : leur
            // total est posé en en-tête par headerColumns(). Appeler
            // replaceItems() les remettrait à zéro (refreshTotals recalcule
            // depuis des lignes inexistantes).
            if (! $document->type->hasGlobalAmount()) {
                $this->replaceItems($document, self::itemsPayload($data));
            }

            $this->numberOnCreate($document, self::requestedStatus($data, $document));

            return $document->refresh()->load('items');
        });
    }

    /**
     * État explicitement demandé par l'appelant, s'il est recevable.
     *
     * Réservé aux types dont l'état est SAISI plutôt que déduit d'une mécanique
     * (situation). Sur une facture, l'état reste la conséquence de son cycle —
     * l'accepter en entrée permettrait de créer une facture « payée » sans
     * qu'aucun règlement n'ait eu lieu.
     *
     * La valeur est confrontée à `allowedFor()` même si le FormRequest l'a déjà
     * fait : ce service est aussi appelé par les seeders et la conversion, qui
     * ne passent par aucune validation HTTP.
     *
     * @param  array<string, mixed>  $data
     */
    private static function requestedStatus(array $data, Document $document): ?DocumentStatus
    {
        if (! $document->type->numbersOnCreate()) {
            return null;
        }

        $status = is_string($data['status'] ?? null)
            ? DocumentStatus::tryFrom($data['status'])
            : null;

        if ($status === null) {
            return null;
        }

        // `draft` et `cancelled` sont écartés bien qu'ils figurent dans
        // `allowedFor()` : le premier signifierait « sans numéro » sur un
        // document qu'on vient de numéroter, le second annulerait une pièce à
        // l'instant de sa création — ce que fait déjà l'endpoint dédié.
        $assignable = DocumentStatus::manuallyAssignableFor($document->type);

        return in_array($status, $assignable, strict: true) ? $status : null;
    }

    /**
     * Numérote immédiatement les types qui n'ont pas d'étape d'émission
     * (situation), et pose leur état de règlement.
     *
     * Appelé DANS la transaction ouverte par create() : DocumentNumberService
     * refuse d'opérer en dehors, le verrou de ligne sur `sequences` n'y
     * tiendrait pas.
     *
     * `issued_at` est renseignée d'office si l'appelant ne l'a pas fournie :
     * elle désigne l'exercice, donc la séquence à incrémenter. Une situation
     * sans date ne saurait pas dans quel millésime se numéroter.
     *
     * `$requested` prime sur la déduction quand il est fourni : l'utilisateur
     * qui déclare « en cours » sait quelque chose que les montants ne disent
     * pas. À défaut, l'état reste déduit de l'avance.
     */
    private function numberOnCreate(Document $document, ?DocumentStatus $requested = null): void
    {
        if (! $document->type->numbersOnCreate()) {
            return;
        }

        $document->issued_at ??= Carbon::now();
        $document->number = $this->numbers->allocate($document->type, $document->issued_at);
        // Le numéro est posé JUSTE AVANT : settlementStatus() s'appuie sur
        // isIssued() pour savoir qu'il y a une créance à qualifier. Une
        // situation saisie avec une avance sort donc directement en « partiel »,
        // sans passer par un « non payé » qui n'aurait jamais été vrai.
        $document->status = $requested ?? $document->settlementStatus();
        $document->save();
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

            // Contrôle croisé APRÈS remplissage, sur l'état résultant : un PATCH
            // partiel peut ne porter que l'un des deux montants, et le
            // FormRequest n'a alors rien à comparer. C'est ici qu'on voit la
            // valeur retenue face à celle déjà en base.
            $this->assertSettlementWithinTotal($document);

            $document->save();

            // Les lignes ne sont retouchées que si le client en a transmis :
            // un PATCH qui ne change qu'une date ne doit pas vider le document.
            if (! $document->type->hasGlobalAmount() && array_key_exists('items', $data)) {
                $this->replaceItems($document, self::itemsPayload($data));
            }

            // Un état explicitement transmis prime. À défaut, corriger le
            // montant ou l'avance d'une situation en change l'état de
            // règlement : passer de 5 000 à 3 000 encaissés sur 3 000 dus la
            // fait basculer de « partiel » à « payé ». Laisser le statut figé
            // afficherait un badge qui contredit les chiffres de la même ligne.
            $requested = self::requestedStatus($data, $document);

            if ($requested !== null) {
                $document->status = $requested;
                $document->save();
            } else {
                $this->refreshSettlementStatus($document);
            }

            return $document->refresh()->load('items');
        });
    }

    /**
     * Réaligne le statut sur les montants, pour les types dont l'état de
     * règlement est DÉDUIT et non choisi.
     *
     * Ne touche ni aux brouillons (pas encore de créance) ni aux états
     * terminaux : une situation annulée le reste, quel qu'en soit le solde.
     *
     * `in_progress` est épargné lui aussi, et c'est essentiel : aucun montant
     * ne dit qu'un chantier est en cours. Sans cette garde, un PATCH ne portant
     * que l'objet ramènerait la situation à « non payé » — l'utilisateur
     * perdrait un état qu'il a posé sciemment, en modifiant tout autre chose.
     */
    private function refreshSettlementStatus(Document $document): void
    {
        if (! $document->type->numbersOnCreate() || ! $document->isIssued()) {
            return;
        }

        if ($document->status->isTerminal() || $document->status === DocumentStatus::InProgress) {
            return;
        }

        $status = $document->settlementStatus();

        if ($status !== $document->status) {
            $document->status = $status;
            $document->save();
        }
    }

    /**
     * Interdit qu'une avance dépasse le montant dû.
     *
     * Doublé par la contrainte `documents_paid_not_above_total_check` : cette
     * garde-ci existe pour rendre un 409 lisible plutôt qu'une violation SQL
     * brute remontée en 500.
     *
     * @throws ConflictHttpException
     */
    private function assertSettlementWithinTotal(Document $document): void
    {
        if ($document->paid_cents > $document->total_cents) {
            throw new ConflictHttpException(__('The advance cannot exceed the total amount.'));
        }
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

        // Un document sans contenu consommerait un numéro de séquence pour ne
        // rien attester. Ce que « contenu » veut dire dépend du type : une
        // ligne au moins pour une facture, un montant non nul pour une situation.
        if ($document->type->hasGlobalAmount()) {
            if ($document->total_cents <= 0) {
                throw new ConflictHttpException(__('A situation must carry an amount to be issued.'));
            }
        } elseif ($document->items()->count() === 0) {
            throw new ConflictHttpException(__('A document must have at least one line to be issued.'));
        }

        return DB::transaction(function () use ($document, $issuedAt): Document {
            $document->issued_at = $issuedAt ?? $document->issued_at ?? Carbon::today();
            $document->due_at ??= $this->defaultDueDate($document);

            // Le millésime du numéro vient de l'EXERCICE couvrant la date
            // d'émission, pas de l'année civile : les exercices décalés existent.
            $document->number = $this->numbers->allocate($document->type, $document->issued_at);
            // `settlementStatus()` a besoin du numéro pour savoir que le
            // document est émis : il est posé juste au-dessus. Une situation
            // créée avec une avance sort donc directement en « partiel », sans
            // passer par un « non payé » qui n'aurait jamais été vrai.
            $document->status = $document->type->isReceivable()
                ? $document->settlementStatus()
                : DocumentStatus::Sent;
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
     * Enregistre le montant TOTAL encaissé sur un document émis, et en déduit
     * son état de règlement.
     *
     * C'est délibérément le CUMUL et non un versement à ajouter : sans table de
     * règlements, un « +1 000 » rejoué par une double soumission gonflerait le
     * montant encaissé sans trace. Un total absolu est idempotent.
     *
     * Cette écriture ne heurte PAS l'immuabilité du §3 : elle ne touche ni au
     * numéro, ni au montant dû, ni aux lignes — rien de ce que le document
     * atteste. Encaisser n'est pas modifier la créance.
     *
     * @throws ConflictHttpException si le document n'est pas encore émis, ou ne
     *                               porte pas de créance
     */
    public function recordSettlement(Document $document, int $paidCents): Document
    {
        if (! $document->type->isReceivable()) {
            throw new ConflictHttpException(__('This document type does not carry a receivable.'));
        }

        if (! $document->isIssued()) {
            throw new ConflictHttpException(__('Issue the document before recording a settlement.'));
        }

        if ($document->status->isTerminal()) {
            throw new ConflictHttpException(__('A cancelled document cannot be settled.'));
        }

        if ($paidCents > $document->total_cents) {
            // Doublé par la contrainte `documents_paid_not_above_total_check` :
            // ici pour rendre un 409 lisible plutôt qu'une erreur SQL brute.
            throw new ConflictHttpException(__('The settled amount exceeds the document total.'));
        }

        $document->paid_cents = max(0, $paidCents);
        $document->status = $document->settlementStatus();
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
     * Supprime le document. Soft delete, jamais un DELETE réel (§3).
     *
     * Portée : les brouillons, plus les types qui ne gèlent pas — la situation
     * depuis le 2026-08-05, la FACTURE depuis le 2026-08-06 sur décision de
     * l'exploitant. Supprimer un document numéroté TROUE sa séquence : le
     * numéro est consommé et ne sera pas réattribué.
     *
     * Sur une situation, la contrepartie est sans portée (pièce non opposable).
     * Sur une facture, elle est réelle et documentée dans
     * `DocumentType::freezesOnIssue()`, qui trace la frontière — c'est là qu'il
     * faut lire ce que la décision coûte, et là qu'on la révoque.
     *
     * Le soft delete reste le mécanisme : même supprimée de l'interface, la
     * facture demeure en base avec son `deleted_at`. L'index unique partiel
     * `(company_id, number)` ne retient QUE les lignes vivantes, si bien que le
     * numéro libéré redevient techniquement disponible — sans conséquence tant
     * que `sequences` n'est jamais rembobinée, ce qu'aucun code ne fait.
     */
    public function delete(Document $document): void
    {
        $this->assertDeletable($document);

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
            'subject' => 'subject',
            'issueCity' => 'issue_city',
            'notes' => 'notes',
            'terms' => 'terms',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        $columns += $this->globalAmountColumns($data, $existing);

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
     * Totaux des documents à MONTANT GLOBAL (situation).
     *
     * C'est l'exception assumée à la règle n°3 de cette classe (« les totaux ne
     * viennent jamais du client ») : une situation ne porte pas de lignes, son
     * montant EST une donnée d'en-tête. L'exception est bornée au seul type qui
     * la justifie, via `hasGlobalAmount()` — jamais un drapeau transmis par
     * l'appelant, qui permettrait de court-circuiter le calcul sur une facture.
     *
     * Pas de ventilation de TVA : `subtotal = total`, taxe et remise à zéro
     * (décision produit du 2026-08-05 — la situation est une pièce de suivi,
     * pas une pièce fiscale).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function globalAmountColumns(array $data, ?Document $existing): array
    {
        // Le type vient du document PERSISTÉ dès qu'il en existe un : sur un
        // PATCH, un `type` transmis dans le payload est ignoré partout
        // ailleurs, et le lire ici permettrait de faire passer une facture
        // pour une situation le temps d'un calcul de totaux.
        $type = $existing instanceof Document
            ? $existing->type
            : (is_string($data['type'] ?? null) ? DocumentType::tryFrom($data['type']) : null);

        if ($type === null || ! $type->hasGlobalAmount()) {
            return [];
        }

        $columns = [];

        if (array_key_exists('totalCents', $data) && is_int($data['totalCents'])) {
            $columns['subtotal_cents'] = $data['totalCents'];
            $columns['discount_cents'] = 0;
            $columns['tax_cents'] = 0;
            $columns['total_cents'] = $data['totalCents'];
        }

        if (array_key_exists('paidCents', $data) && is_int($data['paidCents'])) {
            $columns['paid_cents'] = $data['paidCents'];
        }

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
        if ($document->status->isTerminal() && ! self::reopensWhenTerminal($document)) {
            throw new DocumentNotEditable;
        }

        // Les types qui ne gèlent pas restent modifiables une fois numérotés :
        // la situation (2026-08-05), la facture et le devis (2026-08-06), puis
        // l'avoir (2026-08-07) — décisions de l'exploitant. Aucune pièce
        // commerciale n'est donc plus figée après émission ; seul l'état
        // terminal, contrôlé juste au-dessus, ferme encore un document. La
        // brèche dans l'immuabilité du §3 est
        // bornée par l'enum — jamais par un drapeau transmis par l'appelant,
        // qui la rendrait franchissable depuis n'importe quel point d'appel.
        // Ce que chaque levée coûte est écrit dans
        // DocumentType::freezesOnIssue().
        if (! $document->type->freezesOnIssue()) {
            return;
        }

        if (! $document->status->isEditable()) {
            throw new DocumentNotEditable;
        }
    }

    /**
     * Un document en état TERMINAL se rouvre-t-il malgré tout ?
     *
     * La règle était « non, pour tout le monde » jusqu'au 2026-08-07. Elle est
     * désormais bornée à deux cas, sur demande expresse de l'exploitant.
     *
     * L'ANNULATION ne se rouvre JAMAIS, quel que soit le type. C'est le seul
     * état terminal qui résulte d'un acte délibéré, avec son endpoint propre et
     * sa permission propre (`documents.cancel`) ; rouvrir une pièce annulée
     * effacerait la trace de l'annulation elle-même, et il ne resterait plus
     * rien pour dire qu'elle a eu lieu. Cette borne n'est pas négociable par
     * commodité : sans elle, `cancel` ne signifierait plus rien.
     *
     * Le DEVIS converti ou refusé, lui, se rouvre. Ce que cela coûte :
     *  - CONVERTI — le devis a produit une facture. Le modifier après coup le
     *    fait diverger d'elle sans que rien ne le signale : le client peut
     *    détenir un `DEV-` et un `FAC-` qui ne portent plus les mêmes lignes,
     *    alors que le lien `parent_document_id` continue d'affirmer que l'un
     *    découle de l'autre. En litige, c'est la pièce du client qui parle.
     *  - REFUSÉ — moins lourd : un devis refusé n'engage rien. Le rouvrir pour
     *    représenter une offre revient à écraser l'historique du refus, mais
     *    aucune pièce fiscale n'en dépend.
     */
    private static function reopensWhenTerminal(Document $document): bool
    {
        if ($document->status === DocumentStatus::Cancelled) {
            return false;
        }

        return $document->type === DocumentType::Quote;
    }

    /**
     * Garde de la SUPPRESSION, volontairement distincte de celle de l'édition.
     *
     * Supprimer une pièce numérotée consomme un numéro qui ne sera pas
     * réattribué : la séquence reste trouée, et l'article 145 du CGI marocain
     * ne l'admet pas. Ouvrir l'édition d'un type ne doit donc jamais ouvrir sa
     * suppression au passage — cf. `DocumentType::deletableOnceIssued()`.
     */
    private function assertDeletable(Document $document): void
    {
        // Un état TERMINAL ferme la suppression pour TOUS les types, y compris
        // ceux que `reopensWhenTerminal()` laisse désormais rouvrir à
        // l'édition. Les deux actes ne coûtent pas la même chose : rouvrir un
        // devis converti le fait diverger de sa facture, le SUPPRIMER coupe le
        // lien `parent_document_id` et la facture perd la trace de ce dont elle
        // découle — question qu'on pose précisément en litige. Le soft delete
        // n'y change rien : la relation ne résout plus une ligne supprimée.
        if ($document->status->isTerminal()) {
            throw new DocumentNotEditable;
        }

        // Jamais numéroté : rien n'est consommé, le brouillon se jette.
        if ($document->status->isEditable()) {
            return;
        }

        if (! $document->type->deletableOnceIssued()) {
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
