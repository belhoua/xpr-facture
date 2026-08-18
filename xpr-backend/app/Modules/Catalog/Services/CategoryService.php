<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Familles d'articles. Le `company_id` n'est jamais manipulé ici :
 * BelongsToCompany le renseigne à la création et cloisonne les requêtes (§5).
 */
final class CategoryService
{
    /**
     * @param  array{search?: ?string, active?: ?bool, perPage?: ?int}  $filters
     * @return LengthAwarePaginator<int, Category>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        // withCount plutôt qu'un chargement des lignes : l'écran n'affiche
        // qu'un compteur, les charger serait un N+1 déguisé.
        //
        // Sur `services` et non `products` : l'écran Catalogue ne présente plus
        // que des catégories DE SERVICES (2026-08-18), et un compteur qui
        // engloberait un autre type d'article annoncerait un nombre que la
        // liste ne sait plus montrer.
        $query = Category::query()->withCount('services');

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($active = $filters['active'] ?? null) !== null) {
            $query->where('is_active', $active);
        }

        $perPage = min(max($filters['perPage'] ?? 50, 1), 200);

        return $query->orderBy('name')->paginate($perPage)->withQueryString();
    }

    /**
     * Résout une catégorie de la SOCIÉTÉ ACTIVE.
     *
     * Pas un binding implicite de route : SubstituteBindings s'exécute avant le
     * middleware `tenant`, la résolution se ferait donc hors scope
     * (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
     */
    public function findForCompany(string $id): Category
    {
        $category = Category::query()->find($id);

        if (! $category instanceof Category) {
            throw (new ModelNotFoundException)->setModel(Category::class, [$id]);
        }

        return $category;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Category
    {
        // refresh() : `is_active` a sa valeur par défaut posée par PostgreSQL,
        // l'instance issue de create() la rendrait à null dans le JSON.
        return Category::query()->create($this->toColumns($data))->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function update(Category $category, array $data): Category
    {
        $category->update($this->toColumns($data));

        return $category->refresh();
    }

    /**
     * Archivage et non suppression : les produits de la catégorie restent
     * vendables (la FK est en nullOnDelete, mais le soft delete ne la
     * déclenche pas — le lien survit et redevient valide si l'on restaure).
     */
    public function archive(Category $category): void
    {
        $category->delete();
    }

    /**
     * Traduit le payload camelCase vers les colonnes. Les clés absentes ne sont
     * pas écrites : une mise à jour partielle ne réinitialise rien.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        $map = [
            'name' => 'name',
            'description' => 'description',
            'color' => 'color',
            'isActive' => 'is_active',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        return $columns;
    }
}
