<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Models\Document;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecture des documents commerciaux.
 */
final class DocumentService
{
    /**
     * @param  array{type?: ?string, status?: ?string, search?: ?string, partnerId?: ?string, perPage?: ?int, page?: ?int}  $filters
     * @return LengthAwarePaginator<int, Document>
     */
    public function paginate(array $filters): LengthAwarePaginator
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
