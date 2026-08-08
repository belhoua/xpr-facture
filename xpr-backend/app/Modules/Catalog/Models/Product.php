<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Article du catalogue : bien ou service.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $category_id
 * @property ProductType $type
 * @property string|null $reference
 * @property string $name
 * @property string|null $description
 * @property string $unit
 * @property int $unit_price_cents
 * @property int|null $cost_price_cents
 * @property numeric-string $default_discount_percent
 * @property string $currency
 * @property string|null $tax_rate_id
 * @property bool $track_stock
 * @property bool $is_active
 */
final class Product extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'type',
        'reference',
        'name',
        'description',
        'unit',
        'unit_price_cents',
        'cost_price_cents',
        'default_discount_percent',
        'currency',
        'tax_rate_id',
        'track_stock',
        'is_active',
    ];

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Taux de TVA appliqué PAR DÉFAUT quand l'article est posé sur un document.
     * La ligne de document en fige ensuite une COPIE (`document_items.tax_rate`) :
     * modifier ce taux ici n'altère aucun document déjà saisi (§3).
     *
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * Recherche libre sur le libellé, la référence et la description.
     *
     * ILIKE plutôt qu'un index full-text, pour la même raison que sur
     * `partners` : la saisie utile est partielle et en cours de frappe
     * (« imp » → « Impression »), ce que tsvector, qui indexe des mots entiers,
     * ne couvre pas.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('name', 'ILIKE', "%{$escaped}%")
                ->orWhere('reference', 'ILIKE', "%{$escaped}%")
                ->orWhere('description', 'ILIKE', "%{$escaped}%");
        });
    }

    /**
     * Marge unitaire en centimes, quand le prix de revient est renseigné.
     * `null` — et non 0 — quand il ne l'est pas : une marge inconnue n'est pas
     * une marge nulle, et l'interface doit pouvoir faire la différence.
     */
    public function marginCents(): ?int
    {
        if ($this->cost_price_cents === null) {
            return null;
        }

        return $this->unit_price_cents - $this->cost_price_cents;
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'unit_price_cents' => 'integer',
            'cost_price_cents' => 'integer',
            // Même cast que `document_items.discount_percent`, vers lequel la
            // valeur est recopiée : un flottant introduirait un écart d'arrondi
            // entre la fiche et la ligne de document.
            'default_discount_percent' => 'decimal:2',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
