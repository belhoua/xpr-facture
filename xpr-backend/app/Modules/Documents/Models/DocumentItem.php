<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un document commercial.
 *
 * Les colonnes `label`, `unit`, `unit_price_cents` et `tax_rate` sont des
 * INSTANTANÉS du catalogue au moment de la saisie, pas des projections de
 * `product_id`. Le catalogue peut ensuite être renommé, revalorisé ou changer
 * de taux : le document déjà émis n'en bouge pas (§3). C'est la raison d'être
 * de la duplication apparente avec `products`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $document_id
 * @property string|null $product_id
 * @property int $position
 * @property string $label
 * @property string|null $description
 * @property numeric-string $quantity
 * @property string $unit
 * @property int $unit_price_cents
 * @property numeric-string $discount_percent
 * @property string|null $tax_rate_id
 * @property numeric-string $tax_rate
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 */
final class DocumentItem extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'document_id',
        'product_id',
        'position',
        'label',
        'description',
        'quantity',
        'unit',
        'unit_price_cents',
        'discount_percent',
        'tax_rate_id',
        'tax_rate',
        'subtotal_cents',
        'discount_cents',
        'tax_cents',
        'total_cents',
    ];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Article du catalogue dont la ligne est issue, quand elle n'a pas été
     * saisie librement. Sert à la navigation et aux états de ventes par
     * article — jamais à l'affichage du document, qui lit `label`.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            // decimal:N conserve une CHAÎNE exacte. Un cast 'float' réintroduirait
            // le binaire dans la chaîne de calcul de la TVA (§7).
            'quantity' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'unit_price_cents' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
