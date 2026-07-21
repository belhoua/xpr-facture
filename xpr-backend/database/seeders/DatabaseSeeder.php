<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du seed.
 *
 * Ordre d'exécution :
 *   1. CurrencySeeder  — référentiel des devises (requis par la FK companies.default_currency)
 *   2. RoleSeeder      — rôles Spatie (requis pour DemoSeeder.createTeam)
 *   3. DemoSeeder      — sociétés, utilisateurs, factures, mouvements de caisse
 *
 * Usage :
 *   php artisan migrate:fresh --seed          ← recréer toute la base + peupler
 *   php artisan db:seed                       ← peupler sans recréer (idempotent)
 *   php artisan db:seed --class=DemoSeeder    ← relancer seulement la démo
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Référentiels : prérequis du schéma (companies.default_currency est
        // une FK, les rôles sont requis par le provisioning).
        $this->call([
            CurrencySeeder::class,
            RoleSeeder::class,
        ]);

        // Les données de DÉMO ne doivent pas peupler la base de test : les
        // tests montent leurs propres fixtures via factories, et des sociétés
        // préexistantes fausseraient toute requête non filtrée.
        if ($this->container->environment('testing')) {
            return;
        }

        $this->call(DemoSeeder::class);
    }
}
