<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Catalog\Models\Product;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentItem;

/**
 * Construit les lignes d'un document à partir du payload de l'API.
 *
 * Séparé de DocumentWriteService parce qu'il concentre une responsabilité
 * différente et délicate : décider, pour chaque champ, si la valeur vient du
 * CATALOGUE ou de la SAISIE, puis la figer.
 *
 * Règle de résolution, dans cet ordre :
 *  1. la valeur transmise, si elle l'est — l'utilisateur a le dernier mot, il
 *     doit pouvoir négocier un prix sans dupliquer l'article au catalogue ;
 *  2. à défaut, la valeur du produit référencé ;
 *  3. à défaut, une valeur neutre.
 *
 * Le TAUX DE TVA échappe à cette règle : il n'est jamais lu depuis le payload,
 * seulement RÉSOLU depuis `tax_rates` à partir de l'identifiant transmis. Un
 * client qui pourrait poster « tax_rate: 0 » avec un taux à 20 % fabriquerait
 * des factures fiscalement fausses (§10 : le frontend ne protège rien).
 */
final class DocumentItemBuilder
{
    public function __construct(private readonly DocumentCalculator $calculator) {}

    /**
     * @param  list<array<string, mixed>>  $payload
     * @return list<DocumentItem> lignes NON persistées, prêtes à être sauvées
     */
    public function build(Document $document, array $payload): array
    {
        $products = $this->loadProducts($payload);
        $taxRates = $this->loadTaxRates($payload, $products);

        $items = [];

        foreach ($payload as $index => $row) {
            $productId = self::nullableString($row['productId'] ?? null);
            $product = $productId !== null ? ($products[$productId] ?? null) : null;

            $taxRateId = array_key_exists('taxRateId', $row)
                ? self::nullableString($row['taxRateId'])
                : $product?->tax_rate_id;

            $taxRate = $taxRateId !== null ? ($taxRates[$taxRateId] ?? null) : null;
            // Le taux APPLIQUÉ est figé ici. `0.00` et non NULL quand aucun taux
            // n'est retenu : « pas de TVA » est une décision, pas une absence.
            $taxRateValue = $taxRate instanceof TaxRate ? (string) $taxRate->rate : '0.00';

            $quantity = self::decimal($row['quantity'] ?? null, '1.000');
            // `instanceof` plutôt que `?->` à gauche d'un `??` : l'accès
            // nullsafe y émettrait un avertissement PHP à l'exécution, que le
            // `??` masquerait sans l'empêcher.
            $unitPriceCents = self::cents($row['unitPriceCents'] ?? null)
                ?? ($product instanceof Product ? $product->unit_price_cents : 0);
            $discountPercent = self::decimal($row['discountPercent'] ?? null, '0.00');

            $amounts = $this->calculator->line($quantity, $unitPriceCents, $discountPercent, $taxRateValue);

            $items[] = new DocumentItem([
                'document_id' => $document->id,
                'product_id' => $product?->id,
                // Rang recalculé depuis l'ordre du tableau reçu : c'est l'ordre
                // que l'utilisateur voit à l'écran. Faire confiance à un
                // `position` transmis exposerait à des doublons, que l'index
                // unique (document_id, position) rejetterait brutalement.
                'position' => $index,
                'label' => self::nullableString($row['label'] ?? null)
                    ?? ($product instanceof Product ? $product->name : ''),
                'description' => self::nullableString($row['description'] ?? null) ?? $product?->description,
                'quantity' => $quantity,
                'unit' => self::nullableString($row['unit'] ?? null)
                    ?? ($product instanceof Product ? $product->unit : null)
                    ?? 'unité',
                'unit_price_cents' => $unitPriceCents,
                'discount_percent' => $discountPercent,
                'tax_rate_id' => $taxRate?->id,
                'tax_rate' => $taxRateValue,
                'subtotal_cents' => $amounts['subtotalCents'],
                'discount_cents' => $amounts['discountCents'],
                'tax_cents' => $amounts['taxCents'],
                'total_cents' => $amounts['totalCents'],
            ]);
        }

        return $items;
    }

    /**
     * Charge en UNE requête tous les produits référencés — sinon une facture de
     * 30 lignes déclencherait 30 SELECT. Le scope tenant s'applique : un
     * produit d'une autre société est simplement absent de la table de
     * correspondance, et la ligne retombe en saisie libre (le FormRequest
     * l'aura de toute façon déjà rejetée).
     *
     * @param  list<array<string, mixed>>  $payload
     * @return array<string, Product>
     */
    private function loadProducts(array $payload): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (array $row): ?string => self::nullableString($row['productId'] ?? null),
            $payload,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, Product> $products */
        $products = Product::query()->whereKey($ids)->get()->keyBy('id')->all();

        return $products;
    }

    /**
     * Charge les taux référencés, ceux transmis comme ceux hérités des produits.
     *
     * TaxRate porte BelongsToCompanyOrGlobal : le catalogue standard marocain
     * (company_id NULL) est visible par toutes les sociétés, ce qui est
     * exactement l'intention (cf. migration `create_tax_rates_table`).
     *
     * @param  list<array<string, mixed>>  $payload
     * @param  array<string, Product>  $products
     * @return array<string, TaxRate>
     */
    private function loadTaxRates(array $payload, array $products): array
    {
        $ids = [];

        foreach ($payload as $row) {
            if (array_key_exists('taxRateId', $row)) {
                $ids[] = self::nullableString($row['taxRateId']);

                continue;
            }

            $productId = self::nullableString($row['productId'] ?? null);
            $ids[] = $productId !== null ? ($products[$productId]->tax_rate_id ?? null) : null;
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        /** @var array<string, TaxRate> $rates */
        $rates = TaxRate::query()->whereKey($ids)->get()->keyBy('id')->all();

        return $rates;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Normalise une grandeur décimale en chaîne — jamais en float (§7).
     *
     * Une chaîne non numérique retombe sur le défaut plutôt que d'être
     * transmise telle quelle au calculateur : le FormRequest a déjà validé
     * `numeric`, et laisser passer « abc » jusqu'à un `(float)` silencieux
     * produirait une ligne à zéro sans que personne ne le voie.
     *
     * @param  numeric-string  $default
     * @return numeric-string
     */
    private static function decimal(mixed $value, string $default): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return trim($value);
        }

        return $default;
    }

    private static function cents(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }
}
