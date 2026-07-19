<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'legal_name' => fake()->company(),
            'legal_form' => 'sarl',
            'ice' => (string) fake()->numerify('###############'),
            'if_number' => (string) fake()->numerify('########'),
            'rc_number' => (string) fake()->numerify('######'),
            'rc_city' => fake()->randomElement(['Casablanca', 'Rabat', 'Oujda', 'Tanger']),
            'vat_regime' => 'debit',
            'city' => fake()->city(),
            'country' => 'MA',
            'default_currency' => 'MAD',
            'timezone' => 'Africa/Casablanca',
        ];
    }

    public function autoEntrepreneur(): static
    {
        return $this->state(fn (): array => [
            'legal_form' => 'auto_entrepreneur',
            'vat_exempt' => true,
            'share_capital' => null,
            'rc_number' => null,
            'rc_city' => null,
        ]);
    }
}
