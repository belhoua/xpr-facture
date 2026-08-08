<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 *
 * Articles crédibles d'un prestataire de services marocain. Les prix sont en
 * centimes de MAD (§7) et correspondent aux ordres de grandeur du marché.
 *
 * Aucun `tax_rate_id` par défaut : le taux dépend du catalogue de TVA, qui est
 * seedé à part. Le laisser à null évite qu'une factory ne tire une FK vers une
 * ligne absente — les tests qui en ont besoin le passent explicitement.
 */
final class ProductFactory extends Factory
{
    protected $model = Product::class;

    /** @var list<array{name: string, unit: string, price: int, type: ProductType}> */
    private const CATALOG = [
        ['name' => 'Journée de conseil', 'unit' => 'jour', 'price' => 450_000, 'type' => ProductType::Service],
        ['name' => 'Développement sur mesure', 'unit' => 'heure', 'price' => 60_000, 'type' => ProductType::Service],
        ['name' => 'Maintenance applicative', 'unit' => 'mois', 'price' => 350_000, 'type' => ProductType::Service],
        ['name' => 'Hébergement mutualisé', 'unit' => 'mois', 'price' => 45_000, 'type' => ProductType::Service],
        ['name' => 'Formation utilisateurs', 'unit' => 'jour', 'price' => 380_000, 'type' => ProductType::Service],
        ['name' => 'Audit de sécurité', 'unit' => 'forfait', 'price' => 2_500_000, 'type' => ProductType::Service],
        ['name' => 'Ordinateur portable 14"', 'unit' => 'unité', 'price' => 950_000, 'type' => ProductType::Good],
        ['name' => 'Écran 27" 4K', 'unit' => 'unité', 'price' => 320_000, 'type' => ProductType::Good],
        ['name' => 'Imprimante laser réseau', 'unit' => 'unité', 'price' => 480_000, 'type' => ProductType::Good],
        ['name' => 'Ramette papier A4', 'unit' => 'ramette', 'price' => 5_500, 'type' => ProductType::Good],
    ];

    public function definition(): array
    {
        /** @var array{name: string, unit: string, price: int, type: ProductType} $article */
        $article = $this->faker->randomElement(self::CATALOG);

        return [
            'type' => $article['type']->value,
            'name' => $article['name'],
            'unit' => $article['unit'],
            'unit_price_cents' => $article['price'],
            // Marge brute de 35 % à 60 %, calculée en entiers.
            'cost_price_cents' => intdiv($article['price'] * $this->faker->numberBetween(40, 65), 100),
            // La plupart des articles se vendent au prix plein : une remise
            // par défaut systématique donnerait des jeux d'essai irréalistes.
            'default_discount_percent' => $this->faker->boolean(20) ? $this->faker->randomElement(['5.00', '10.00']) : '0.00',
            'currency' => 'MAD',
            // Seuls les biens sont stockables : la contrainte CHECK
            // `products_stock_goods_only_check` refuserait un service coché.
            'track_stock' => $article['type'] === ProductType::Good,
            'is_active' => true,
        ];
    }

    public function service(): static
    {
        return $this->state(fn (array $a): array => [
            'type' => ProductType::Service->value,
            'track_stock' => false,
        ]);
    }

    public function good(): static
    {
        return $this->state(fn (array $a): array => ['type' => ProductType::Good->value]);
    }
}
