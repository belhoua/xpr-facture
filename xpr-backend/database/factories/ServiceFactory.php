<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Services\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
final class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * Noms tirés d'une liste FIXE et plausible pour un bureau de contrôle
     * marocain : un `word()` de faker produirait des libellés qui ne
     * ressemblent à rien dans une capture d'écran de démonstration.
     *
     * @var list<string>
     */
    private const NAMES = [
        'Contrôle technique de construction',
        'Assistance à maîtrise d\'ouvrage',
        'Diagnostic structure',
        'Étude géotechnique',
        'Coordination SPS',
        'Vérification des installations électriques',
        'Suivi de chantier',
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            // `unique()` : l'index partiel refuse deux noms identiques dans une
            // même société, et un seed en lot les heurterait sans lui.
            'name' => $this->faker->unique()->randomElement(self::NAMES),
        ];
    }
}
