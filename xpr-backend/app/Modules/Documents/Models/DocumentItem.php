<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Catalog\Models\Product;
use App\Modules\Shared\Concerns\BelongsToCompany;
use App\Modules\Tenancy\Services\TenantContext;
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

    /**
     * Persiste des lignes NON ENCORE SAUVÉES en UNE SEULE requête.
     *
     * Les lignes partaient une à une : une facture de trente postes tenait
     * trente INSERT, donc trente allers-retours réseau à l'intérieur de la
     * transaction qui valide le document — c'est-à-dire pendant que la ligne
     * de séquence est verrouillée. Sur une base distante (Neon, quelques
     * millisecondes par aller-retour), la latence de la saisie dépendait
     * linéairement du nombre de postes.
     *
     * ── Ce que `insert()` NE FAIT PAS, et qu'il faut donc faire ici ────────
     *
     * L'insertion en masse court-circuite les événements Eloquent. Or deux
     * d'entre eux posent des colonnes NOT NULL :
     *  - `HasUuids` fournit la clé primaire — sans elle, l'INSERT est rejeté ;
     *  - `BelongsToCompany` pose `company_id` — sans lui, la ligne serait
     *    orpheline, et la RLS PostgreSQL la refuserait de toute façon (§5).
     *
     * Les deux sont donc reproduits à l'identique, en réutilisant les méthodes
     * du modèle plutôt qu'en les réimplémentant : `newUniqueId()` reste la
     * source de l'identifiant, `TenantContext::requireId()` celle de la
     * société — y compris son refus d'écrire hors contexte tenant.
     *
     * @param  list<self>  $items  lignes construites, jamais persistées
     */
    public static function insertMany(array $items): void
    {
        if ($items === []) {
            return;
        }

        $now = (new self)->freshTimestamp();

        $rows = [];

        foreach ($items as $item) {
            if ($item->getKey() === null) {
                $item->setAttribute($item->getKeyName(), $item->newUniqueId());
            }

            if ($item->getAttribute('company_id') === null) {
                $item->setAttribute('company_id', app(TenantContext::class)->requireId());
            }

            $item->setCreatedAt($now);
            $item->setUpdatedAt($now);

            // `getAttributes()` rend exactement ce que `save()` aurait envoyé :
            // les casts `decimal:N` sont des accesseurs de LECTURE, la valeur
            // stockée est déjà la chaîne exacte attendue par PostgreSQL (§7).
            $rows[] = $item->getAttributes();

            // Les modèles restent utilisables par l'appelant après coup —
            // `DocumentWriteService` recalcule les totaux depuis eux.
            $item->exists = true;
            $item->syncOriginal();
        }

        self::query()->insert($rows);
    }

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
