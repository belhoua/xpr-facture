<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecture et écriture du catalogue.
 */
final class ProductService
{
    /**
     * @param  array{search?: ?string, type?: ?string, categoryId?: ?string, active?: ?bool, perPage?: ?int}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        // Eager loading : la liste affiche le nom de la catégorie et le taux de
        // TVA de chaque ligne — sans cela, 50 produits font 100 requêtes.
        $query = Product::query()->with(['category', 'taxRate']);

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($type = $filters['type'] ?? null) !== null) {
            $query->where('type', ProductType::from($type)->value);
        }

        if (($categoryId = $filters['categoryId'] ?? null) !== null) {
            // Le scope tenant s'applique : un identifiant appartenant à une
            // autre société ne remonte simplement aucune ligne.
            $query->where('category_id', $categoryId);
        }

        if (($active = $filters['active'] ?? null) !== null) {
            $query->where('is_active', $active);
        }

        $perPage = min(max($filters['perPage'] ?? 25, 1), 200);

        return $query->orderBy('name')->paginate($perPage)->withQueryString();
    }

    /** Résout un article de la SOCIÉTÉ ACTIVE (cf. CategoryService::findForCompany). */
    public function findForCompany(string $id): Product
    {
        $product = Product::query()->with(['category', 'taxRate'])->find($id);

        if (! $product instanceof Product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
        }

        return $product;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Product
    {
        $product = Product::query()->create($this->toColumns($data));

        return $product->refresh()->load(['category', 'taxRate']);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Product $product, array $data): Product
    {
        $product->update($this->toColumns($data));

        return $product->refresh()->load(['category', 'taxRate']);
    }

    /**
     * Archivage et non suppression : l'article est référencé par des lignes de
     * documents que la loi impose de conserver. La FK `document_items.product_id`
     * est d'ailleurs en RESTRICT — une suppression physique échouerait.
     */
    public function archive(Product $product): void
    {
        $product->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        $map = [
            'categoryId' => 'category_id',
            'type' => 'type',
            'reference' => 'reference',
            'name' => 'name',
            'description' => 'description',
            'unit' => 'unit',
            'unitPriceCents' => 'unit_price_cents',
            'costPriceCents' => 'cost_price_cents',
            'defaultDiscountPercent' => 'default_discount_percent',
            'currency' => 'currency',
            'taxRateId' => 'tax_rate_id',
            'trackStock' => 'track_stock',
            'isActive' => 'is_active',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        // Colonnes NOT NULL à valeur par défaut. Le formulaire transmet `null`
        // pour « champ laissé vide » — convention légitime côté client, mais
        // que PostgreSQL rejetterait en 23502. On retombe sur le défaut de la
        // colonne, ce qui rend aussi le comportement correct en MISE À JOUR :
        // vider l'unité doit la ramener à « unité », pas conserver l'ancienne.
        foreach (['unit' => 'unité', 'default_discount_percent' => '0'] as $column => $default) {
            if (array_key_exists($column, $columns) && $columns[$column] === null) {
                $columns[$column] = $default;
            }
        }

        // Un service ne se stocke pas — c'est déjà une contrainte CHECK en base.
        // On corrige ici plutôt que de laisser remonter une erreur SQL brute :
        // cocher « suivi de stock » puis basculer en « service » est une
        // séquence naturelle dans un formulaire, pas une tentative de fraude.
        if (($columns['type'] ?? null) === ProductType::Service->value) {
            $columns['track_stock'] = false;
        }

        return $columns;
    }
}
