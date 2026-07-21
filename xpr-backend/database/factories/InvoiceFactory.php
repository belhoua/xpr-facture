<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Invoices\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 *
 * Montants en centimes de MAD (§7 du CLAUDE.md).
 * Les plages sont représentatives des PME marocaines :
 *  - Petites prestations : 500 – 15 000 DH
 *  - Services moyens     : 15 000 – 80 000 DH
 *  - Gros contrats       : 80 000 – 500 000 DH
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** Noms de clients marocains fictifs crédibles. */
    private const CLIENTS = [
        'Société Atlas Technologies S.A.R.L.',
        'Cabinet Comptable Tazi & Associés',
        'Riad Marrakech Hôtellerie',
        'Café du Port Casablanca',
        'Boulangerie El Wiam',
        'Transport Logistique Souss',
        'Bureau d\'Études Technique Darna',
        'Agence Immobilière Horizon',
        'Pharmacie Al Shifa',
        'Clinique Médicale Errazi',
        'École Privée Excellence',
        'Auto-École Permis Plus',
        'Garage Auto Mécanique Idrissi',
        'Traiteur Événementiel Al Baraka',
        'Imprimerie Rapide Fez',
        'Studio Graphique Concept',
        'Consulting RH Maghreb',
        'IT Solutions Maroc',
        'Menuiserie Artisanale Bensouda',
        'Superette Al Amal',
    ];

    public function definition(): array
    {
        $status = $this->faker->randomElement(['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled']);
        $issuedAt = $this->faker->dateTimeBetween('-6 months', 'now');
        $dueAt = (clone $issuedAt)->modify('+30 days');

        // Les brouillons n'ont pas de numéro, les autres oui.
        $number = $status === 'draft'
            ? null
            : sprintf('FAC-%d-%04d', now()->year, $this->faker->unique()->numberBetween(1, 9999));

        // Montant crédible : centimes, arrondi à 100 DH (= 10 000 centimes).
        $totalCents = $this->faker->randomElement([
            fn () => $this->faker->numberBetween(50, 1500) * 10_000,   // 500–15 000 DH
            fn () => $this->faker->numberBetween(1500, 8000) * 10_000,  // 15 000–80 000 DH
            fn () => $this->faker->numberBetween(8000, 50000) * 10_000, // 80 000–500 000 DH
        ])();

        return [
            'number' => $number,
            'client_name' => $this->faker->randomElement(self::CLIENTS),
            'issued_at' => $issuedAt->format('Y-m-d'),
            'due_at' => $dueAt->format('Y-m-d'),
            'status' => $status,
            'total_cents' => $totalCents,
            'currency' => 'MAD',
        ];
    }

    /** État : brouillon (sans numéro). */
    public function draft(): static
    {
        return $this->state(fn (array $a) => [
            'status' => 'draft',
            'number' => null,
        ]);
    }

    /** État : envoyée, en attente de paiement. */
    public function sent(): static
    {
        return $this->state(fn (array $a) => ['status' => 'sent']);
    }

    /** État : partiellement réglée. */
    public function partial(): static
    {
        return $this->state(fn (array $a) => ['status' => 'partial']);
    }

    /** État : intégralement payée. */
    public function paid(): static
    {
        return $this->state(fn (array $a) => ['status' => 'paid']);
    }

    /** État : en retard (échéance dépassée, non réglée). */
    public function overdue(): static
    {
        return $this->state(fn (array $a) => [
            'status' => 'overdue',
            'issued_at' => $this->faker->dateTimeBetween('-4 months', '-2 months')->format('Y-m-d'),
            'due_at' => $this->faker->dateTimeBetween('-6 weeks', '-1 week')->format('Y-m-d'),
        ]);
    }

    /** État : annulée. */
    public function cancelled(): static
    {
        return $this->state(fn (array $a) => ['status' => 'cancelled']);
    }
}
