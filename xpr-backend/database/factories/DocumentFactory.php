<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 *
 * Montants en centimes de MAD (§7). Les plages sont représentatives des PME
 * marocaines :
 *  - Petites prestations : 500 – 15 000 DH
 *  - Services moyens     : 15 000 – 80 000 DH
 *  - Gros contrats       : 80 000 – 500 000 DH
 *
 * Les totaux produits ici sont COHÉRENTS entre eux (HT + TVA = TTC) mais ne
 * s'appuient sur aucune ligne : la factory sert aux tests d'en-tête et de
 * liste. Dès qu'un test porte sur le CALCUL, il doit passer par
 * DocumentWriteService avec de vraies lignes — c'est lui qui fait foi.
 */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

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
        $status = $this->faker->randomElement([
            DocumentStatus::Draft, DocumentStatus::Sent, DocumentStatus::Partial,
            DocumentStatus::Paid, DocumentStatus::Overdue, DocumentStatus::Cancelled,
        ]);

        // Borné à l'exercice courant : le provisioning n'ouvre que l'année
        // civile en cours, et un document daté hors exercice n'aurait aucune
        // séquence à laquelle se rattacher.
        $issuedAt = $this->faker->dateTimeBetween(now()->startOfYear(), 'now');
        $dueAt = (clone $issuedAt)->modify('+30 days');

        // AUCUN numéro ici. La factory ne connaît pas la séquence de la
        // société : inventer un numéro aléatoire produirait une numérotation
        // trouée et désordonnée, et pourrait entrer en collision avec le
        // compteur réel. C'est l'appelant (DemoSeeder, DocumentWriteService)
        // qui numérote.

        // Base HT crédible : centimes, arrondie à 100 DH (= 10 000 centimes).
        $subtotalCents = $this->faker->randomElement([
            fn (): int => $this->faker->numberBetween(50, 1500) * 10_000,   // 500–15 000 DH
            fn (): int => $this->faker->numberBetween(1500, 8000) * 10_000,  // 15 000–80 000 DH
            fn (): int => $this->faker->numberBetween(8000, 50000) * 10_000, // 80 000–500 000 DH
        ])();

        // TVA au taux standard marocain, en arithmétique entière (§7).
        $taxCents = intdiv($subtotalCents * 20, 100);

        return [
            'type' => DocumentType::Invoice->value,
            'number' => null,
            'client_name' => $this->faker->randomElement(self::CLIENTS),
            'issued_at' => $issuedAt->format('Y-m-d'),
            'due_at' => $dueAt->format('Y-m-d'),
            'status' => $status->value,
            'subtotal_cents' => $subtotalCents,
            'discount_cents' => 0,
            'tax_cents' => $taxCents,
            'total_cents' => $subtotalCents + $taxCents,
            'currency' => 'MAD',
        ];
    }

    /** Type : devis. */
    public function quote(): static
    {
        return $this->state(fn (array $a): array => ['type' => DocumentType::Quote->value]);
    }

    /** État : brouillon (sans numéro). */
    public function draft(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => DocumentStatus::Draft->value,
            'number' => null,
        ]);
    }

    /** État : émis, en attente de règlement. */
    public function sent(): static
    {
        return $this->state(fn (array $a): array => ['status' => DocumentStatus::Sent->value]);
    }

    /** État : partiellement réglé. */
    public function partial(): static
    {
        return $this->state(fn (array $a): array => ['status' => DocumentStatus::Partial->value]);
    }

    /** État : intégralement réglé. */
    public function paid(): static
    {
        return $this->state(fn (array $a): array => ['status' => DocumentStatus::Paid->value]);
    }

    /** État : en retard (échéance dépassée, non réglé). */
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
                'status' => DocumentStatus::Overdue->value,
                'issued_at' => $issuedAt->format('Y-m-d'),
                'due_at' => (clone $issuedAt)->modify('+30 days')->format('Y-m-d'),
            ];
        });
    }

    /** État : annulé. */
    public function cancelled(): static
    {
        return $this->state(fn (array $a): array => ['status' => DocumentStatus::Cancelled->value]);
    }
}
