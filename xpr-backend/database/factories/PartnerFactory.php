<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Partners\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 *
 * Tiers marocains fictifs crédibles : raisons sociales, villes et formes
 * juridiques réelles du tissu TPE/PME.
 */
final class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    /** @var list<array{legal: string, trade: ?string, city: string}> */
    private const COMPANIES = [
        ['legal' => 'Atlas Distribution S.A.R.L.', 'trade' => 'Atlas Distrib', 'city' => 'Casablanca'],
        ['legal' => 'TechMaroc Solutions S.A.R.L. AU', 'trade' => 'TechMaroc', 'city' => 'Rabat'],
        ['legal' => 'Société Riad Azur S.A.', 'trade' => 'Riad Azur', 'city' => 'Marrakech'],
        ['legal' => 'Boulangerie Al Fath', 'trade' => null, 'city' => 'Fès'],
        ['legal' => 'Cabinet Tazi & Associés', 'trade' => null, 'city' => 'Casablanca'],
        ['legal' => 'Transport Logistique Souss S.A.R.L.', 'trade' => 'TLS', 'city' => 'Agadir'],
        ['legal' => 'Imprimerie Rapide Fès', 'trade' => null, 'city' => 'Fès'],
        ['legal' => 'Menuiserie Bensouda', 'trade' => null, 'city' => 'Meknès'],
        ['legal' => 'Pharmacie Al Shifa', 'trade' => null, 'city' => 'Tanger'],
        ['legal' => 'Consulting RH Maghreb S.A.R.L.', 'trade' => 'CRH Maghreb', 'city' => 'Casablanca'],
    ];

    public function definition(): array
    {
        $company = $this->faker->randomElement(self::COMPANIES);

        return [
            // Les TROIS types commerciaux, jamais `intermediary` : celui-ci ne
            // remonte dans aucune liste de facturation (cf. PartnerType), et
            // l'introduire au hasard rendrait intermittents des tests qui
            // demandent simplement « un tiers ». Il se demande explicitement,
            // par l'état `intermediary()`.
            'type' => $this->faker->randomElement([
                PartnerType::Client->value,
                PartnerType::Supplier->value,
                PartnerType::Both->value,
            ]),
            'code' => null,
            'legal_name' => $company['legal'],
            'trade_name' => $company['trade'],
            'legal_form' => $this->faker->randomElement(['sarl', 'sarl_au', 'sa', 'auto_entrepreneur']),
            // ICE : 15 chiffres, comme la contrainte CHECK. `unique()` évite de
            // heurter l'index unique par société lors d'un seed en lot.
            'ice' => (string) $this->faker->unique()->numerify('###############'),
            'if_number' => (string) $this->faker->numerify('########'),
            'rc_number' => (string) $this->faker->numerify('######'),
            'rc_city' => $company['city'],
            'contact_name' => $this->faker->name(),
            'email' => $this->faker->companyEmail(),
            'phone' => '+212 5'.$this->faker->numerify('## ## ## ##'),
            'address' => $this->faker->streetAddress(),
            'city' => $company['city'],
            'country' => 'MA',
            'currency' => 'MAD',
            'payment_terms_days' => $this->faker->randomElement([0, 15, 30, 45, 60]),
            'is_active' => true,
        ];
    }

    public function client(): static
    {
        return $this->state(fn (array $a): array => ['type' => PartnerType::Client->value]);
    }

    public function supplier(): static
    {
        return $this->state(fn (array $a): array => ['type' => PartnerType::Supplier->value]);
    }

    /** Tiers à la fois client et fournisseur — cas courant, à couvrir. */
    public function both(): static
    {
        return $this->state(fn (array $a): array => ['type' => PartnerType::Both->value]);
    }

    /** Apporteur d'affaires, courtier : un rôle, pas un sens de facturation. */
    public function intermediary(): static
    {
        return $this->state(fn (array $a): array => ['type' => PartnerType::Intermediary->value]);
    }

    /** Particulier : ni ICE ni IF ni RC. */
    public function individual(): static
    {
        return $this->state(fn (array $a): array => [
            'legal_name' => $this->faker->name(),
            'trade_name' => null,
            'legal_form' => null,
            'ice' => null,
            'if_number' => null,
            'rc_number' => null,
            'rc_city' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $a): array => ['is_active' => false]);
    }
}
