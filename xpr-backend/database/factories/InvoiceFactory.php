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

        // Borné à l'exercice courant : le provisioning n'ouvre que l'année
        // civile en cours, et une facture datée hors exercice n'aurait aucune
        // séquence à laquelle se rattacher.
        $issuedAt = $this->faker->dateTimeBetween(now()->startOfYear(), 'now');
        $dueAt = (clone $issuedAt)->modify('+30 days');

        // AUCUN numéro ici. La factory ne connaît pas la séquence de la
        // société : inventer un numéro aléatoire produisait une numérotation
        // trouée et désordonnée, et pouvait entrer en collision avec le
        // compteur réel. C'est l'appelant (DemoSeeder) qui numérote, dans
        // l'ordre chronologique, en consommant `sequences`.

        // Montant crédible : centimes, arrondi à 100 DH (= 10 000 centimes).
        $totalCents = $this->faker->randomElement([
            fn () => $this->faker->numberBetween(50, 1500) * 10_000,   // 500–15 000 DH
            fn () => $this->faker->numberBetween(1500, 8000) * 10_000,  // 15 000–80 000 DH
            fn () => $this->faker->numberBetween(8000, 50000) * 10_000, // 80 000–500 000 DH
        ])();

        return [
            'number' => null,
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
        return $this->state(function (array $a): array {
            // Comme dans definition() : on ne sort pas de l'exercice courant.
            $earliest = now()->startOfYear();
            $issuedAt = $this->faker->dateTimeBetween(
                now()->subMonths(4)->max($earliest),
                now()->subMonths(2)->max($earliest),
            );

            return [
                'status' => 'overdue',
                'issued_at' => $issuedAt->format('Y-m-d'),
                'due_at' => (clone $issuedAt)->modify('+30 days')->format('Y-m-d'),
            ];
        });
    }

    /** État : annulée. */
    public function cancelled(): static
    {
        return $this->state(fn (array $a) => ['status' => 'cancelled']);
    }
}
