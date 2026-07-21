<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Cash\Models\CashMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 *
 * Mouvements de caisse signés (§7) : positif = encaissement, négatif = décaissement.
 * Les libellés sont en français marocain, les registres reflètent les banques
 * et caisses physiques d'une PME casablancaise.
 */
final class CashMovementFactory extends Factory
{
    protected $model = CashMovement::class;

    /** Registres de trésorerie courants dans une PME marocaine. */
    private const REGISTERS = [
        'Compte Courant Attijariwafa',
        'Compte CIH Professionnel',
        'Compte BMCE Business',
        'Caisse Petite Trésorerie',
        'Carte Corporate Attijariwafa',
    ];

    /** Libellés d'encaissements réalistes. */
    private const INFLOW_LABELS = [
        'Encaissement Facture %s',
        'Virement client %s',
        'Règlement par chèque – %s',
        'Acompte reçu – %s',
        'Paiement partiel – %s',
        'Encaissement espèces – %s',
    ];

    /** Libellés de décaissements réalistes. */
    private const OUTFLOW_LABELS = [
        'Achat fournitures de bureau',
        'Règlement loyer mensuel',
        'Règlement hébergement serveur',
        'Abonnement logiciel comptable',
        'Frais de déplacement',
        'Paiement fournisseur',
        'Charges sociales CNSS',
        'Remboursement avance salariale',
        'Achat matériel informatique',
        'Frais bancaires mensuels',
        'Abonnement téléphonique professionnel',
        'Règlement électricité/eau',
    ];

    /** Noms de clients pour les encaissements. */
    private const CLIENT_NAMES = [
        'Atlas Technologies',
        'Cabinet Tazi',
        'Riad Marrakech',
        'IT Solutions Maroc',
        'Transport Souss',
        'Agence Horizon',
        'Studio Concept',
        'Consulting RH',
    ];

    public function definition(): array
    {
        $isInflow = $this->faker->boolean(60); // 60 % d'encaissements

        if ($isInflow) {
            $labelTemplate = $this->faker->randomElement(self::INFLOW_LABELS);
            $label = sprintf($labelTemplate, $this->faker->randomElement(self::CLIENT_NAMES));
            $amountCents = $this->faker->numberBetween(50, 25000) * 10_000; // 500–250 000 DH
            $method = $this->faker->randomElement(['transfer', 'cheque', 'cash', 'card']);
        } else {
            $label = $this->faker->randomElement(self::OUTFLOW_LABELS);
            $amountCents = -1 * $this->faker->numberBetween(5, 5000) * 10_000; // -50–-50 000 DH
            $method = $this->faker->randomElement(['cash', 'card', 'transfer']);
        }

        return [
            'occurred_at' => $this->faker->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'label' => $label,
            'method' => $method,
            'register_name' => $this->faker->randomElement(self::REGISTERS),
            'amount_cents' => $amountCents,
            'currency' => 'MAD',
        ];
    }

    /** Forcer un encaissement (montant positif). */
    public function inflow(int $amountCents): static
    {
        return $this->state(fn (array $a) => [
            'amount_cents' => abs($amountCents),
        ]);
    }

    /** Forcer un décaissement (montant négatif). */
    public function outflow(int $amountCents): static
    {
        return $this->state(fn (array $a) => [
            'amount_cents' => -abs($amountCents),
        ]);
    }
}
