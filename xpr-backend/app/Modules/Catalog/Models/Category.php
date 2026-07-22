<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

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
     * Articles de la catégorie. `nullOnDelete` en base : archiver une catégorie
     * ne retire pas ses produits du catalogue, elle les déclasse.
     *
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
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
