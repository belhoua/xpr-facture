<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 *
 * Familles usuelles d'un catalogue de TPE/PME marocaine. Le nom est tiré d'une
 * liste fermée et non généré par Faker : l'index unique
 * `categories_company_name_unique` refuserait un doublon, et une collision
 * aléatoire ferait échouer un test sans rapport avec ce qu'il vérifie.
 * `unique()` du générateur garantit l'absence de répétition dans un même run.
 */
final class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /** @var list<array{name: string, color: string}> */
    private const FAMILIES = [
        ['name' => 'Prestations', 'color' => '#2563EB'],
        ['name' => 'Licences & abonnements', 'color' => '#7C3AED'],
        ['name' => 'Matériel informatique', 'color' => '#059669'],
        ['name' => 'Fournitures de bureau', 'color' => '#D97706'],
        ['name' => 'Transport & logistique', 'color' => '#DC2626'],
        ['name' => 'Formation', 'color' => '#0891B2'],
        ['name' => 'Maintenance', 'color' => '#4B5563'],
        ['name' => 'Communication', 'color' => '#DB2777'],
    ];

    public function definition(): array
    {
        /** @var array{name: string, color: string} $family */
        $family = $this->faker->unique()->randomElement(self::FAMILIES);

        return [
            'name' => $family['name'],
            'color' => $family['color'],
            'is_active' => true,
        ];
    }
}
