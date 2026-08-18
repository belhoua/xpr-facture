<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Models\Document;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;
use ValueError;

/**
 * Lecture des documents commerciaux.
 *
 * @phpstan-type Filters array{
 *     type?: string|array<int|string, mixed>|null,
 *     status?: ?string,
 *     search?: ?string,
 *     partnerId?: ?string,
 *     projectId?: ?string,
 *     from?: ?string,
 *     to?: ?string,
 *     perPage?: ?int,
 *     page?: ?int,
 * }
 */
final class DocumentService
{
    /**
     * Montant encaissé d'UN document, en SQL : les règlements vivants s'ils
     * existent, sinon l'avance portée par le document (cf. `summary()`).
     *
     * `deleted_at IS NULL` est écrit ici à la main : la sous-requête est du SQL
     * brut, le global scope de soft delete de `Payment` ne s'y applique pas, et
     * un règlement retiré continuerait d'être compté.
     *
     * Le filtre `company_id` est volontairement absent : la sous-requête est
     * corrélée à `documents.id`, une clé primaire UUID déjà restreinte à la
     * société par le scope de la requête englobante — et la RLS couvre
     * `payments` de son côté (§5).
     */
    private const SETTLED_CENTS_SQL = <<<'SQL'
        COALESCE(
            (
                SELECT SUM(p.amount_cents)
                FROM payments p
                WHERE p.invoice_id = documents.id AND p.deleted_at IS NULL
            ),
            documents.paid_cents
        )
        SQL;

    /**
     * Totaux d'un ensemble de documents, calculés EN BASE.
     *
     * Alimente les quatre indicateurs de l'écran « situations par client ».
     * Un endpoint dédié plutôt qu'une somme côté client : la liste est paginée,
     * additionner les 25 lignes affichées donnerait un total faux dès la 26ᵉ
     * situation — et faux silencieusement, ce qui est pire.
     *
     * ── D'où vient « PAYÉ » ────────────────────────────────────────────────
     *
     * Des RÈGLEMENTS RÉELLEMENT ENREGISTRÉS, `payments`, et non de la seule
     * colonne `documents.paid_cents`. Les deux coïncident sur une facture —
     * `PaymentWriteService::refreshSettlement()` recalcule le cumul à chaque
     * écriture — mais coïncider n'est pas être la même chose : le jour où un
     * import, une reprise de données ou une écriture concurrente les fait
     * diverger, c'est la table des règlements qui dit la vérité, parce qu'elle
     * porte les pièces.
     *
     * Le repli sur `paid_cents` n'est pas une précaution, c'est le cas de la
     * SITUATION : son avance est saisie en en-tête et n'a aucun règlement
     * derrière elle. Un `SUM(payments)` sec afficherait « payé : 0 » sur tout
     * un écran de situations pourtant avancées. D'où le COALESCE, qui se lit :
     * les règlements font foi, à défaut l'avance portée par le document.
     *
     * `remainingCents` se déduit de la différence des deux sommes, et non d'un
     * `SUM(total - paid)` : sur des colonnes NOT NULL les deux sont
     * équivalents, mais la soustraction reste juste si une ligne venait à
     * porter un NULL, là où l'expression agrégée l'écarterait entièrement.
     *
     * @param  Filters  $filters
     * @return array{count: int, totalCents: int, paidCents: int, remainingCents: int}
     */
    public function summary(array $filters): array
    {
        /** @var object{aggregate_count: int|string, total: int|string|null, paid: int|string|null} $row */
        $row = $this->filtered($filters)
            ->toBase()
            ->selectRaw(sprintf(
                'COUNT(*) AS aggregate_count, COALESCE(SUM(total_cents), 0) AS total, COALESCE(SUM(%s), 0) AS paid',
                self::SETTLED_CENTS_SQL,
            ))
            ->first();

        $total = (int) $row->total;
        $paid = (int) $row->paid;

        return [
            'count' => (int) $row->aggregate_count,
            'totalCents' => $total,
            'paidCents' => $paid,
            'remainingCents' => max(0, $total - $paid),
        ];
    }

    /**
     * Socle de filtrage partagé par la liste et les totaux.
     *
     * Factorisé pour une raison de justesse et non d'économie : si les deux
     * appliquaient leurs filtres séparément, une divergence ferait afficher des
     * indicateurs qui ne correspondraient plus aux lignes en dessous.
     *
     * @param  Filters  $filters
     * @return Builder<Document>
     */
    private function filtered(array $filters): Builder
    {
        $query = Document::query();

        $types = $this->resolveTypes($filters['type'] ?? null);

        if ($types !== []) {
            $query->whereIn('type', $types);
        }

        if (($status = $filters['status'] ?? null) !== null && $status !== '') {
            $query->where('status', $status);
        }

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($partnerId = $filters['partnerId'] ?? null) !== null) {
            $query->where('partner_id', $partnerId);
        }

        // Filtre par PROJET : il restreint la liste ET les totaux, puisque les
        // deux passent ici. C'est ce qui permet aux quatre indicateurs de
        // répondre « combien ce chantier-là a-t-il rapporté » plutôt que de
        // donner l'encours de tout le client.
        //
        // Aucun croisement avec `partnerId` n'est nécessaire : un projet
        // n'appartient qu'à un client, et `DocumentWriteService` refuse de
        // rattacher une pièce au projet d'un autre. Poser les deux filtres
        // ensemble reste donc cohérent, et le fait sans requête de plus.
        if (($projectId = $filters['projectId'] ?? null) !== null && $projectId !== '') {
            $query->where('project_id', $projectId);
        }

        // Plage de dates sur la date d'ÉMISSION, celle qui fait foi — et non
        // sur `created_at`, qui ne dit que le moment de la saisie. Les deux
        // bornes sont indépendantes : filtrer « depuis le 1er janvier » sans
        // borne de fin est le cas le plus courant.
        if (($from = $filters['from'] ?? null) !== null && $from !== '') {
            $query->whereDate('issued_at', '>=', $from);
        }

        if (($to = $filters['to'] ?? null) !== null && $to !== '') {
            $query->whereDate('issued_at', '<=', $to);
        }

        return $query;
    }

    /**
     * Types de documents demandés, sous LES DEUX formes reçues du client.
     *
     * `?type=situation,invoice` — la liste séparée par des virgules qu'émet le
     * front — et `?type[]=situation&type[]=invoice`, la forme naturelle d'un
     * paramètre répété en HTTP, que produit n'importe quel client tiers.
     * N'accepter que la première n'était pas un choix : `Request::string()`
     * convertissait le tableau en la chaîne « Array », que `DocumentType::from()`
     * faisait ensuite exploser en 500 — l'écran d'un client se serait vidé sur
     * une requête pourtant légitime.
     *
     * ── Un type inconnu est REFUSÉ, pas ignoré ────────────────────────────
     *
     * `from()` et non `tryFrom()` : ignorer silencieusement `type=facture`
     * renverrait TOUS les documents en réponse à une demande précise — un écran
     * de situations afficherait des devis sans que rien ne le signale. Le
     * `ValueError` est converti en 422 : la faute est dans la requête, et un 500
     * dirait à tort que le serveur a un problème.
     *
     * @param  string|array<int|string, mixed>|null  $type
     * @return list<string> vide = aucun filtre de type
     *
     * @throws ValidationException
     */
    private function resolveTypes(string|array|null $type): array
    {
        if ($type === null) {
            return [];
        }

        $values = is_array($type) ? $type : explode(',', $type);

        $cleaned = [];

        foreach ($values as $value) {
            // Un client peut envoyer `type[]=`, `type[][]=x` ou `type[]=1` :
            // on ne retient que des chaînes non vides, le reste est du bruit
            // qu'il vaut mieux écarter ici que laisser atteindre l'enum.
            if (is_string($value) && trim($value) !== '') {
                $cleaned[] = trim($value);
            }
        }

        try {
            return array_values(array_unique(array_map(
                static fn (string $value): string => DocumentType::from($value)->value,
                $cleaned,
            )));
        } catch (ValueError) {
            throw ValidationException::withMessages([
                'type' => [__('validation.in', ['attribute' => 'type'])],
            ]);
        }
    }

    /**
     * @param  Filters  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($filters);

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        // Les RÈGLEMENTS sont chargés, pas les lignes de détail : l'écran
        // « situations par client » affiche l'historique d'encaissement de
        // chaque ligne, et le lire document par document ferait 25 requêtes
        // par page. Une seule requête supplémentaire les couvre toutes.
        return $query
            // Le PROJET est chargé avec les règlements : l'écran par client
            // affiche son titre sur chaque ligne, et le lire document par
            // document ferait 25 requêtes par page.
            ->with(['project', 'payments' => static fn (Relation $relation): Relation => $relation
                ->orderByDesc('paid_on')
                ->orderByDesc('created_at')])
            // Les brouillons n'ont pas de date d'émission : `created_at` en
            // second critère les range en tête, à leur date de saisie, plutôt
            // que de les laisser flotter en fin de liste.
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $filters['page'] ?? null)
            ->withQueryString();
    }

    /**
     * Résout un document de la SOCIÉTÉ ACTIVE, lignes comprises.
     *
     * Pas un binding implicite de route : SubstituteBindings s'exécute avant le
     * middleware `tenant`, la résolution se ferait donc hors scope et
     * exposerait le document d'une autre société
     * (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
     */
    public function findForCompany(string $id): Document
    {
        $document = Document::query()
            ->with([
                'items',
                'parent',
                'project',
                'payments' => static fn (Relation $relation): Relation => $relation
                    ->orderByDesc('paid_on')
                    ->orderByDesc('created_at'),
            ])
            ->find($id);

        if (! $document instanceof Document) {
            throw (new ModelNotFoundException)->setModel(Document::class, [$id]);
        }

        return $document;
    }
}
