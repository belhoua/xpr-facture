<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Point d'entrée unique du seed — RÉFÉRENTIELS SYSTÈME UNIQUEMENT.
 *
 * Ordre d'exécution :
 *   1. CurrencySeeder — référentiel des devises (requis par la FK companies.default_currency)
 *   2. RoleSeeder     — rôles ET permissions Spatie (matrice de Tenancy/Enums/Role)
 *   3. TaxRateSeeder  — catalogue TVA standard, partagé par toutes les sociétés
 *   4. AdminSeeder    — société par défaut + son propriétaire, seul compte créé
 *
 * ── Aucune donnée métier ici, dans AUCUN environnement ─────────────────────
 *
 * `DemoSeeder` et `ConventionSeeder` existent toujours, mais ne sont plus
 * appelés automatiquement : un `migrate:fresh --seed` livre une base vierge de
 * toute société, de tout tiers, de tout document. Ils restent invocables à la
 * main pour monter un environnement de démonstration commerciale :
 *
 *   php artisan db:seed --class=DemoSeeder
 *   php artisan db:seed --class=ConventionSeeder   ← après DemoSeeder, dont il
 *                                                    reprend la société
 *
 * La raison est double. D'abord la confusion : des factures numérotées par le
 * moteur réel sont indiscernables de vraies factures une fois en base, et rien
 * ne signale à l'exploitant que son chiffre d'affaires est inventé. Ensuite le
 * risque d'exploitation : un seed est rejoué en production bien plus souvent
 * qu'on ne l'admet, et la numérotation, elle, est irréversible (§3).
 *
 * La seule exception est le COMPTE D'AMORÇAGE (`AdminSeeder`) : la société de
 * l'exploitant et son propriétaire, sans le moindre tiers ni document. Ce n'est
 * pas une donnée de démonstration mais la condition pour se connecter — une
 * base sans aucun compte ne renvoie que des 401, ce qui se lit à l'écran comme
 * une panne générale. En production, il faut `XPR_ADMIN_PASSWORD` ou bien
 * `php artisan xpr:create-admin` (saisie masquée) ; le seeder s'abstient sinon.
 *
 * Usage :
 *   php artisan migrate:fresh --seed   ← recréer toute la base + amorçage
 *   php artisan db:seed                ← référentiels + amorçage (idempotent)
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Prérequis du schéma (companies.default_currency est une FK, les rôles
        // sont requis par le provisioning) et catalogue TVA, que toute société
        // consomme dès sa première ligne de facture. Rien d'autre.
        $this->call([
            CurrencySeeder::class,
            RoleSeeder::class,
            TaxRateSeeder::class,
        ]);

        // APRÈS RoleSeeder, dont il consomme le rôle owner : le provisioning
        // appelle assignRole('owner'), qui échouerait sur un rôle inexistant.
        $this->call(AdminSeeder::class);
    }
}
