<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Famille d'articles du catalogue.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string|null $description
 * @property string|null $color
 * @property bool $is_active
 */
final class Category extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'color',
        'is_active',
    ];

    /**
     * Tout ce que la catégorie classe. `nullOnDelete` en base : archiver une
     * catégorie ne retire rien du catalogue, elle déclasse.
     *
     * Conservée pour la contrainte de suppression et les lectures internes ;
     * l'INTERFACE, elle, ne parle plus que de services (cf. `services()`).
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * SERVICES de la catégorie — les seules prestations que la société vend
     * (décision de l'exploitant, 2026-08-18 : l'entreprise ne commercialise
     * aucun bien physique).
     *
     * Relation distincte plutôt qu'un filtre posé par chaque appelant : le
     * compteur de l'écran Catégories doit dire « 4 services », et un
     * `withCount('products')` y ferait entrer un article d'un autre type le
     * jour où il en existerait un — sans que rien ne le signale.
     *
     * @return HasMany<Product, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Product::class)->where('type', ProductType::Service->value);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where('name', 'ILIKE', "%{$escaped}%");
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
