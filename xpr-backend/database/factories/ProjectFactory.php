<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 *
 * Intitulés représentatifs d'un bureau d'études et de contrôle marocain — le
 * métier auquel s'adresse ce module. `partner_id` n'a PAS de valeur par
 * défaut : le laisser créer un tiers à la volée en fabriquerait un hors du
 * contexte tenant, et la colonne étant NOT NULL, l'appelant doit dire pour quel
 * client le projet est mené.
 */
final class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /** @var list<string> */
    private const TITLES = [
        'Résidence Al Manar — lot A',
        'Extension usine Aïn Sebaâ',
        'Contrôle technique immeuble Yasmine',
        'Lotissement Les Oliviers — tranche 2',
        'Réhabilitation médina de Fès',
        'Centre commercial Anfa Place',
        'Complexe scolaire Ibn Batouta',
        'Station de traitement Souss',
        'Villa Palmeraie — suivi de chantier',
        'Entrepôt logistique Zenata',
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $status = $this->faker->randomElement(ProjectStatus::cases());

        return [
            'title' => $this->faker->randomElement(self::TITLES),
            'status' => $status->value,
            'progress_percentage' => $this->progressFor($status),
            'description' => null,
        ];
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => ProjectStatus::InProgress->value,
            'progress_percentage' => $this->faker->numberBetween(5, 90),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => ProjectStatus::Completed->value,
            'progress_percentage' => 100,
        ]);
    }

    public function monitoring(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => ProjectStatus::Monitoring->value,
            'progress_percentage' => 100,
        ]);
    }

    public function canceled(): static
    {
        return $this->state(fn (array $a): array => [
            'status' => ProjectStatus::Canceled->value,
        ]);
    }

    /**
     * Avancement COHÉRENT avec l'état : un projet « achevé » à 40 % ne
     * ressemble à rien, et des fixtures incohérentes font douter d'un écran qui
     * fonctionne. L'annulé garde un avancement partiel — c'est justement là
     * qu'il s'est arrêté.
     */
    private function progressFor(ProjectStatus $status): int
    {
        return match ($status) {
            ProjectStatus::Completed, ProjectStatus::Monitoring => 100,
            ProjectStatus::InProgress => $this->faker->numberBetween(5, 90),
            ProjectStatus::Canceled => $this->faker->numberBetween(0, 60),
        };
    }
}
