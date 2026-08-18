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
use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les documents commerciaux. Concentre les règles fiscales du §3
 * qu'aucun contrôleur ne doit rejouer :
 *
 *  1. **Un document naît TOUJOURS brouillon.** L'API ne permet pas de créer un
 *     document déjà émis. Le numéro s'attribue à l'émission, dans la même
 *     transaction — c'est la seule façon de garantir une séquence sans trou.
 *  2. **Un document émis est gelé.** Ni édition, ni suppression : l'annulation
 *     passe par le statut `cancelled`, jamais par un DELETE.
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
    /**
     * Nom de la fiche client CRÉÉE au cours de l'écriture en cours, s'il y en a
     * eu une.
     *
     * État intermédiaire assumé : `clientSnapshot()` ne rend que des colonnes,
     * il n'a pas le document sous la main — celui-ci n'existe pas encore au
     * moment où le tiers doit être résolu. La valeur est reportée sur le
     * document rendu, puis oubliée.
     *
     * Remis à `null` à l'entrée de chaque écriture : le conteneur peut très
     * bien servir la même instance à deux appels d'une même requête, et un
     * drapeau qui traîne annoncerait une création qui n'a pas eu lieu.
     */
    private ?string $createdPartnerName = null;

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly DocumentCalculator $calculator,
        private readonly DocumentItemBuilder $itemBuilder,
    ) {}

    /**
     * Crée un document.
     *
     * Il naît BROUILLON pour les types qui ont une étape d'émission, et
     * NUMÉROTÉ pour ceux que `numbersOnCreate()` désigne — la situation depuis
     * le 2026-08-05, la facture et le devis depuis le 2026-08-14, sur décision
     * de l'exploitant. Le coût de cette seconde levée (numéros brûlés par une
     * double soumission, absence d'idempotence sur la route) est détaillé dans
     * `DocumentType::numbersOnCreate()`.
     *
     * La bascule est portée par l'ENUM et par lui seul : aucun paramètre
     * d'appel ne permet de numéroter un type qui ne le demande pas, sans quoi
     * la règle deviendrait franchissable depuis n'importe quel point d'appel.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Document
    {
        $manualNumber = self::manualNumber($data);
        $this->createdPartnerName = null;

        try {
            return DB::transaction(function () use ($data, $manualNumber): Document {
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

                $this->numberOnCreate(
                    $document,
                    self::requestedStatus($data, $document),
                    $manualNumber,
                );

                return $this->withCreatedPartner($document->refresh()->load('items'));
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Le FormRequest a bien vérifié l'unicité, mais entre son contrôle
            // et l'INSERT une autre requête a pu prendre le numéro. L'index
            // `documents_company_number_unique` est le seul arbitre fiable ; ce
            // catch traduit son verdict en 409 plutôt qu'en 500.
            //
            // Restreint au numéro MANUEL : sur une numérotation automatique, une
            // violation d'unicité signalerait une séquence désynchronisée, un
            // défaut qu'il vaut mieux voir remonter que masquer en 409.
            if ($manualNumber === null) {
                throw $exception;
            }

            throw new ConflictHttpException(
                __('This number is already used by another document.'),
            );
        }
    }

    /**
     * Numéro saisi à la main, ou `null` si l'appelant laisse la séquence faire.
     *
     * Lu ICI et transmis en paramètre plutôt que d'être ajouté à
     * `headerColumns()` : cette dernière sert aussi la mise à jour, et y placer
     * `number` rendrait un document renumérotable par un simple PATCH.
     *
     * La chaîne est conservée telle quelle, zéros initiaux compris : « 007 »
     * est un numéro que l'utilisateur a écrit, pas un entier à normaliser.
     *
     * @param  array<string, mixed>  $data
     */
    private static function manualNumber(array $data): ?string
    {
        $number = $data['number'] ?? null;

        if (is_int($number)) {
            $number = (string) $number;
        }

        if (! is_string($number)) {
            return null;
        }

        $number = trim($number);

        return $number === '' ? null : $number;
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
        if (! $document->type->statusFollowsSettlement()) {
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
     * (situation, facture, devis), et pose leur état de règlement.
     *
     * Appelé DANS la transaction ouverte par create() : DocumentNumberService
     * refuse d'opérer en dehors, le verrou de ligne sur `sequences` n'y
     * tiendrait pas.
     *
     * `issued_at` est renseignée d'office si l'appelant ne l'a pas fournie :
     * elle désigne l'exercice, donc la séquence à incrémenter. Un document sans
     * date ne saurait pas dans quel millésime se numéroter.
     *
     * `$requested` prime sur la déduction quand il est fourni — mais seule la
     * situation en fournit un (cf. `requestedStatus()`). Pour une facture ou un
     * devis, l'état est TOUJOURS déduit : `settlementStatus()` rend `sent` dès
     * lors qu'un numéro est posé et qu'aucune avance n'est saisie, ce qui est
     * exactement l'état visé par la décision du 2026-08-14.
     */
    private function numberOnCreate(
        Document $document,
        ?DocumentStatus $requested = null,
        ?string $manualNumber = null,
    ): void {
        if (! $document->type->numbersOnCreate()) {
            return;
        }

        // Même garde qu'à l'émission, et pour la même raison : un document sans
        // contenu consommerait un numéro de séquence pour ne rien attester.
        // Elle DEVAIT suivre la numérotation quand celle-ci a migré vers la
        // création — la laisser sur le seul `issue()` l'aurait rendue
        // inatteignable pour les types qui ne passent plus par là.
        $this->assertHasContent($document);

        // Variable locale et non `$document->issued_at` relu ensuite : la
        // propriété reste typée `Carbon|null`, et c'est elle qui date la
        // séquence à incrémenter — la garantie de non-nullité doit être visible.
        $issuedAt = $document->issued_at ?? Carbon::now();
        $document->issued_at = $issuedAt;

        // L'ÉCHÉANCE par défaut suivait le numéro dans `issue()` : date
        // d'émission + délai de règlement convenu avec le tiers. Elle devait
        // donc le suivre ici aussi — sans elle, une facture qui ne passe plus
        // par `issue()` naîtrait sans échéance, et ni le passage en `overdue`
        // ni les relances n'auraient de date à laquelle se déclencher.
        $document->due_at ??= $this->defaultDueDate($document);

        $document->number = $this->resolveNumber($document, $issuedAt, $manualNumber);
        // Le numéro est posé JUSTE AVANT : settlementStatus() s'appuie sur
        // isIssued() pour savoir qu'il y a une créance à qualifier. Une
        // situation saisie avec une avance sort donc directement en « partiel »,
        // sans passer par un « non payé » qui n'aurait jamais été vrai.
        $document->status = $requested ?? $document->settlementStatus();
        $document->save();
    }

    /**
     * Numéro du document : celui SAISI par l'utilisateur, ou celui de la
     * séquence à défaut.
     *
     * Ouvert le 2026-08-14 à la demande de l'exploitant, contre l'avis porté
     * par ce dépôt. Ce que cela coûte, dit ici pour que personne n'ait à le
     * redécouvrir :
     *
     *  - la séquence N'AVANCE PAS sur un numéro saisi. Le compteur reste où il
     *    était, et la numérotation automatique reprendra là où elle en était —
     *    les pièces d'une même société ne sont donc plus dans un ordre unique ;
     *  - le format n'est plus garanti. Un numéro saisi vaut « 42 », là où la
     *    séquence produit « FAC-2026-0042 ». Deux conventions coexistent dans
     *    la même colonne, et rien ne dit laquelle fait foi pour l'antériorité ;
     *  - l'article 145 du CGI marocain exige une numérotation CONTINUE et sans
     *    trou. Saisir « 42 » puis « 44 » crée un trou que rien ne comblera, et
     *    le produit ne peut plus le détecter : il ne sait pas si « 43 » est un
     *    oubli ou un numéro qui n'a jamais existé.
     *
     * Ce que cela ne casse PAS, et qu'il ne faut pas relâcher :
     *  - l'unicité par société reste tenue par l'index partiel
     *    `documents_company_number_unique`, doublé du contrôle du FormRequest ;
     *  - la numérotation AUTOMATIQUE est inchangée : même verrou de ligne, même
     *    exigence de transaction, mêmes garanties de continuité. Une société qui
     *    ne saisit jamais de numéro ne perd rien de ce qui précède ;
     *  - aucune COLLISION n'est possible entre les deux mondes tant que le
     *    format des séquences porte un préfixe : « 42 » et « FAC-2026-0042 »
     *    sont deux chaînes distinctes. Un format de séquence réduit aux seuls
     *    chiffres ferait tomber cette garantie — et l'index remonterait alors
     *    un 409 à la première rencontre, plutôt qu'un doublon silencieux.
     */
    private function resolveNumber(
        Document $document,
        Carbon $issuedAt,
        ?string $manualNumber,
    ): string {
        if ($manualNumber !== null) {
            return $manualNumber;
        }

        // Le millésime vient de l'EXERCICE couvrant la date d'émission, pas de
        // l'année civile : les exercices décalés existent.
        return $this->numbers->allocate($document->type, $issuedAt);
    }

    /**
     * Met à jour un BROUILLON — en-tête et lignes.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Document $document, array $data): Document
    {
        $this->assertEditable($document);
        $this->createdPartnerName = null;

        try {
            return $this->writeUpdate($document, $data);
        } catch (UniqueConstraintViolationException $exception) {
            // Le FormRequest a bien vérifié l'unicité, mais entre son contrôle
            // et l'UPDATE une autre requête a pu prendre le numéro — d'autant
            // plus probable depuis que la renumérotation est ouverte, la
            // séquence continuant d'attribuer en parallèle. L'index
            // `documents_company_number_unique` est le seul arbitre fiable ; ce
            // catch traduit son verdict en 409 plutôt qu'en 500.
            if (! array_key_exists('number', $data)) {
                throw $exception;
            }

            throw new ConflictHttpException(
                __('This number is already used by another document.'),
            );
        }
    }

    /**
     * Le corps transactionnel de `update()`, isolé pour que la traduction de
     * la collision d'unicité enveloppe la transaction ENTIÈRE — un `catch` posé
     * à l'intérieur s'exécuterait avant le COMMIT, moment où PostgreSQL peut
     * encore lever.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeUpdate(Document $document, array $data): Document
    {
        return DB::transaction(function () use ($document, $data): Document {
            $document->fill($this->headerColumns($data, $document));

            // Le NUMÉRO est traité ICI et non dans `headerColumns()`, partagée
            // avec la création : l'y placer rendrait tout document
            // renumérotable par un simple PATCH, y compris les types auxquels
            // l'exploitant n'a rien demandé.
            $this->applyRenumbering($document, $data);

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

            return $this->withCreatedPartner($document->refresh()->load('items'));
        });
    }

    /**
     * Réécrit le numéro d'une pièce déjà numérotée, quand son type l'autorise.
     *
     * ── Trois gardes, et aucune n'est décorative ─────────────────────────
     *
     * 1. Le TYPE doit ouvrir la renumérotation (`allowsNumberEdit()`, qui porte
     *    le coût de cette levée). Le FormRequest le refuse déjà en 422 ; la
     *    garde est reprise ici parce que ce service sert aussi les seeders, la
     *    conversion et la console, qui ne passent par aucune validation HTTP.
     * 2. La pièce doit DÉJÀ porter un numéro. Sur un brouillon, `number` reste
     *    l'affaire de l'émission : le poser par un PATCH court-circuiterait
     *    `numberOnCreate()`, donc la séquence, la garde de contenu et l'exercice
     *    comptable qui date le millésime.
     * 3. La clé ABSENTE ne touche à rien. Corriger une note ne doit pas
     *    renuméroter, et le formulaire d'un autre type n'émet pas ce champ.
     *
     * ── Ce que cette méthode NE fait PAS ─────────────────────────────────
     *
     * Elle ne touche pas au compteur de `sequences`, et c'est délibéré : le
     * faire avancer jusqu'au numéro saisi creuserait un trou de plusieurs
     * numéros jamais attribués, le faire reculer réattribuerait des numéros
     * déjà consommés — deux façons opposées de contredire l'article 145 du CGI.
     * La conséquence, dite dans `allowsNumberEdit()`, est qu'une collision peut
     * survenir bien plus tard, sur une pièce sans rapport ; l'index unique la
     * refusera alors.
     *
     * Elle ne conserve pas non plus l'ancien numéro : le dépôt n'a pas de
     * journal d'audit sur ce champ, et en improviser un ici — une colonne, une
     * note automatique — serait une décision de conception prise en passant.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyRenumbering(Document $document, array $data): void
    {
        if (! array_key_exists('number', $data)) {
            return;
        }

        if (! $document->type->allowsNumberEdit() || ! $document->isIssued()) {
            return;
        }

        $number = self::manualNumber($data);

        // Vide ou absent de fait : on ne rend pas une pièce à l'état non
        // numéroté, ce qui libérerait son numéro pour une autre tout en
        // laissant celle-ci circuler avec le numéro déjà imprimé.
        if ($number === null) {
            return;
        }

        $document->number = $number;
    }

    /**
     * Reporte sur le document la fiche client créée en cours de route.
     *
     * Propriété PHP déclarée et non attribut Eloquent : un attribut serait
     * candidat à la persistance au prochain `save()`, et `documents` n'a pas
     * — et ne doit pas avoir — de colonne pour un fait qui ne concerne que la
     * réponse en cours.
     */
    private function withCreatedPartner(Document $document): Document
    {
        $document->autoCreatedPartnerName = $this->createdPartnerName;
        $this->createdPartnerName = null;

        return $document;
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
        if (! $document->type->statusFollowsSettlement() || ! $document->isIssued()) {
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
     * Un document sans contenu ne peut pas consommer un numéro : il occuperait
     * une place dans la séquence pour ne rien attester.
     *
     * Ce que « contenu » veut dire dépend du type — une ligne au moins pour une
     * facture ou un devis, un montant non nul pour une situation.
     *
     * Appelée depuis les DEUX points de numérotation, `issue()` et
     * `numberOnCreate()`. C'était une garde de `issue()` seul jusqu'au
     * 2026-08-14 ; depuis que la facture et le devis se numérotent à la
     * création, ils ne passent plus par `issue()` et l'auraient contournée.
     *
     * @throws ConflictHttpException
     */
    private function assertHasContent(Document $document): void
    {
        if ($document->type->hasGlobalAmount()) {
            if ($document->total_cents <= 0) {
                throw new ConflictHttpException(__('A situation must carry an amount to be issued.'));
            }

            return;
        }

        if ($document->items()->count() === 0) {
            throw new ConflictHttpException(__('A document must have at least one line to be issued.'));
        }
    }

    /**
     * ÉMET le document : lui attribue son numéro et le gèle.
     *
     * Point de bascule fiscal pour les types qui ont encore une étape
     * d'émission. Depuis le 2026-08-14, la facture, le devis et la situation
     * n'en font plus partie : ils naissent numérotés, et cet endpoint leur
     * répond 409 puisqu'ils ne sont plus `draft`. Il reste le chemin des types
     * d'achat et d'expédition, et celui des brouillons créés AVANT la bascule.
     *
     * Trois gardes, dans cet ordre :
     *  - il doit être encore brouillon (on ne renumérote pas) ;
     *  - il doit porter du contenu (`assertHasContent()`) ;
     *  - la numérotation se fait DANS la transaction, sinon le verrou de ligne
     *    de `sequences` ne tient pas (DocumentNumberService le refuse d'ailleurs).
     */
    public function issue(Document $document, ?Carbon $issuedAt = null): Document
    {
        if (! $document->status->isEditable()) {
            throw new ConflictHttpException(__('This document has already been issued.'));
        }

        $this->assertHasContent($document);

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

        // UN seul INSERT pour toutes les lignes (cf. DocumentItem::insertMany) :
        // la boucle de `save()` qui l'a précédée coûtait un aller-retour par
        // poste, à l'intérieur de la transaction d'écriture.
        DocumentItem::insertMany($items);

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

        $partnerGiven = array_key_exists('partnerId', $data);

        $partnerId = $partnerGiven
            ? (is_string($data['partnerId']) ? $data['partnerId'] : null)
            : $existing?->partner_id;

        // Le snapshot a le dernier mot sur `partner_id` : il peut en poser un
        // que l'appelant n'a pas transmis, quand le nom libre saisi ouvre une
        // fiche. `+=` ne remplacerait pas la clé déjà présente.
        $columns = array_merge($columns, $this->clientSnapshot($data, $partnerId, $partnerGiven, $existing));

        // APRÈS le snapshot, jamais avant : le client du document n'est connu
        // qu'une fois celui-ci résolu — une saisie au nom libre peut même
        // l'avoir créé à l'instant.
        $columns += $this->projectColumn($data, $columns, $existing);

        return $columns;
    }

    /**
     * Projet facturé par ce document, vérifié contre SON client.
     *
     * ── La règle que la base ne sait pas écrire ──────────────────────────
     *
     * Un document ne peut porter que le projet de son propre client. La
     * condition croise `documents.partner_id` et `projects.partner_id` : ni un
     * CHECK ni une clé étrangère ne l'expriment, elle se tient donc ici. Sans
     * elle, un identifiant deviné — ou un formulaire resté ouvert pendant qu'on
     * change de client — rattacherait la facture d'un client au chantier d'un
     * autre, et les totaux par projet deviendraient faux sans que rien ne
     * l'annonce.
     *
     * 422 et non 409 : la faute porte sur un champ précis du formulaire, et
     * l'écran doit pouvoir la poser sous le bon déroulant.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $columns  colonnes déjà résolues, `partner_id` compris
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function projectColumn(array $data, array $columns, ?Document $existing): array
    {
        if (! array_key_exists('projectId', $data)) {
            return [];
        }

        $projectId = is_string($data['projectId']) && $data['projectId'] !== ''
            ? $data['projectId']
            : null;

        // Détacher est toujours permis : un rattachement erroné doit pouvoir se
        // défaire sans qu'on ait à en fournir un autre.
        if ($projectId === null) {
            return ['project_id' => null];
        }

        // Requête SCOPÉE, jamais une règle `exists` seule : celle-ci
        // interrogerait `projects` sans le global scope tenant (§5.3).
        $project = Project::query()->find($projectId);

        if (! $project instanceof Project) {
            throw ValidationException::withMessages([
                'projectId' => [__('The selected project does not belong to this company.')],
            ]);
        }

        $partnerId = array_key_exists('partner_id', $columns)
            ? $columns['partner_id']
            : $existing?->partner_id;

        if ($project->partner_id !== $partnerId) {
            throw ValidationException::withMessages([
                'projectId' => [__('The selected project belongs to another client.')],
            ]);
        }

        return ['project_id' => $project->id];
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
     * Identité du client à FIGER sur le document, et le tiers auquel le
     * rattacher.
     *
     * ── Le nom libre OUVRE UNE FICHE (décision du 2026-08-17) ─────────────
     *
     * Un document sans `partner_id` n'est rattaché à personne : il n'apparaît
     * dans aucun écran par client, dans aucun encours, dans aucun classement —
     * il porte le bon nom et reste pourtant introuvable par son propre client.
     * C'est ce qui vidait l'écran « situations du client ».
     *
     * Le nom saisi librement crée donc désormais la fiche du tiers, et le
     * document s'y rattache. Le « client de passage » disparaît en tant qu'état
     * durable : il devient une SAISIE RAPIDE — on tape un nom, la fiche naît
     * avec ce seul nom, on la complète plus tard.
     *
     * ── Réutiliser plutôt que dupliquer ──────────────────────────────────
     *
     * La recherche se fait sur la raison sociale, insensible à la casse et aux
     * espaces de bord, parmi les tiers VIVANTS qui peuvent être clients
     * (`client` ou `both`). Un tiers archivé l'a été délibérément : le
     * ressusciter au détour d'une facture déciderait à la place de celui qui
     * l'a rangé.
     *
     * Ce que ce rapprochement NE fait pas, et qu'il ne faut pas lui demander :
     * reconnaître « ACME SARL » dans « Acme S.A.R.L. ». Deux graphies donnent
     * deux fiches. C'est délibéré — un rapprochement approximatif attribuerait
     * les créances d'un client à un autre, ce qui coûte bien plus cher qu'un
     * doublon qu'on fusionne à la main.
     *
     * ── Corriger le nom sur une pièce déjà rattachée ─────────────────────
     *
     * Le tiers EXPLICITEMENT transmis gagne toujours : il est l'identité
     * légale, et un `clientName` qui l'accompagnerait ne doit pas la contredire.
     * Mais un PATCH partiel qui ne porte QUE `clientName` est autre chose — le
     * tiers y est hérité du document, pas choisi. Corriger « Brouilon » en
     * « Brouillon » désigne alors un autre client, et le traiter comme un nom
     * libre est la seule lecture qui laisse la correction possible. Sans cela,
     * la moindre coquille exigerait de passer par la fiche du tiers.
     *
     * @param  array<string, mixed>  $data
     * @param  bool  $partnerGiven  le payload porte-t-il `partnerId` ?
     * @return array<string, mixed>
     */
    private function clientSnapshot(
        array $data,
        ?string $partnerId,
        bool $partnerGiven,
        ?Document $existing,
    ): array {
        $name = $data['clientName'] ?? null;
        $name = is_string($name) && trim($name) !== '' ? trim($name) : null;

        // Un tiers HÉRITÉ du document cède au nom corrigé ; un tiers
        // explicitement transmis, jamais.
        $freeNameWins = $name !== null && ! $partnerGiven;

        if ($partnerId !== null && ! $freeNameWins) {
            // Le scope tenant s'applique : un tiers d'une autre société est
            // introuvable, et le FormRequest l'a déjà écarté.
            $partner = Partner::query()->find($partnerId);

            if ($partner instanceof Partner) {
                return [
                    'partner_id' => $partner->id,
                    // La RAISON SOCIALE, pas l'enseigne : le document commercial
                    // engage l'entité légale.
                    'client_name' => $partner->legal_name,
                    'client_ice' => $partner->ice,
                    'client_address' => $partner->address,
                ];
            }
        }

        if ($name !== null) {
            $partner = $this->partnerForFreeName($name);

            return [
                'partner_id' => $partner->id,
                // Le nom FIGÉ reste celui de la fiche : les deux ne diffèrent
                // que par les espaces de bord et la casse d'un tiers retrouvé,
                // et c'est la fiche qui fait foi sur l'identité légale.
                'client_name' => $partner->legal_name,
                'client_ice' => $partner->ice,
                'client_address' => $partner->address,
            ];
        }

        return $existing !== null ? [] : ['client_name' => ''];
    }

    /**
     * Le tiers portant ce nom, retrouvé ou créé.
     *
     * Appelée dans la transaction du document : une fiche créée pour une
     * facture qui échoue ensuite ne doit pas rester derrière elle.
     *
     * La fiche naît avec le SEUL nom. Aucune valeur n'est devinée — ni ICE, ni
     * adresse, ni téléphone : ce sont des mentions légales (§3), et en inventer
     * une seule ferait imprimer sur une facture un identifiant fiscal que
     * personne n'a vérifié. Les colonnes non renseignées gardent leurs défauts
     * de schéma (`MAD`, `MA`, 30 jours), qui sont des conventions, pas des
     * affirmations sur ce client.
     *
     * Le nom de la fiche CRÉÉE est exposé sur le document retourné
     * (`autoCreatedPartnerName`) : l'interface doit pouvoir dire qu'une fiche
     * vient de naître et reste à compléter. Un tiers simplement RETROUVÉ ne le
     * renseigne pas — il n'y a rien à annoncer.
     */
    private function partnerForFreeName(string $name): Partner
    {
        $existing = Partner::query()
            ->ofType(PartnerType::Client)
            ->whereRaw('LOWER(BTRIM(legal_name)) = ?', [mb_strtolower($name)])
            // Rien n'interdit deux fiches homonymes — l'unicité ne porte que
            // sur l'ICE et le code. Sans ordre, PostgreSQL en rendrait une au
            // hasard, et deux factures du même client pourraient partir sur
            // deux fiches différentes. La PLUS ANCIENNE gagne : c'est celle qui
            // porte déjà l'historique.
            ->oldest()
            ->first();

        if ($existing instanceof Partner) {
            return $existing;
        }

        $partner = Partner::query()->create([
            'type' => PartnerType::Client->value,
            'legal_name' => $name,
        ]);

        $this->createdPartnerName = $partner->legal_name;

        return $partner;
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
        // la situation (2026-08-05), la facture et le devis (2026-08-06) —
        // décisions de l'exploitant. Aucune pièce commerciale de vente n'est
        // donc plus figée après émission ; seul l'état
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
