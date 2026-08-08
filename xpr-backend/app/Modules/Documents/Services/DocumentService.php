<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Models\Document;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecture des documents commerciaux.
 *
 * @phpstan-type Filters array{
 *     type?: ?string,
 *     status?: ?string,
 *     search?: ?string,
 *     partnerId?: ?string,
 *     from?: ?string,
 *     to?: ?string,
 *     perPage?: ?int,
 *     page?: ?int,
 * }
 */
final class DocumentService
{
    /**
     * Totaux d'un ensemble de documents, calculés EN BASE.
     *
     * Alimente les quatre indicateurs de l'écran « situations par client ».
     * Un endpoint dédié plutôt qu'une somme côté client : la liste est paginée,
     * additionner les 25 lignes affichées donnerait un total faux dès la 26ᵉ
     * situation — et faux silencieusement, ce qui est pire.
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
            ->selectRaw('COUNT(*) AS aggregate_count, COALESCE(SUM(total_cents), 0) AS total, COALESCE(SUM(paid_cents), 0) AS paid')
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

        if (($type = $filters['type'] ?? null) !== null) {
            $query->ofType(DocumentType::from($type));
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
     * @param  Filters  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->filtered($filters);

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $query
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
        $document = Document::query()->with(['items', 'parent'])->find($id);

        if (! $document instanceof Document) {
            throw (new ModelNotFoundException)->setModel(Document::class, [$id]);
        }

        return $document;
    }
}
