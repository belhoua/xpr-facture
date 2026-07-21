<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Synchronise les rôles et leurs permissions depuis la matrice de
 * `Tenancy\Enums\Role`.
 *
 * Pourquoi une MIGRATION et pas seulement un seeder : depuis que chaque route
 * exige une permission, une base dont la table `permissions` est vide refuse
 * TOUT à TOUS les utilisateurs — y compris aux owners. Sur une installation
 * existante, déployer le RBAC sans rejouer le seeder coupe donc l'accès à
 * l'application entière. Reproduit sur la base de développement : login 200,
 * puis 403 sur chaque endpoint.
 *
 * Le faire ici garantit que la synchronisation accompagne le déploiement, au
 * lieu de dépendre d'un `db:seed` que personne ne pense à lancer.
 *
 * Rejouable sans risque : le seeder crée par `findOrCreate` et applique
 * `syncPermissions`. Il sera relancé à chaque évolution de la matrice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = new RoleSeeder;
        $seeder->setContainer(app());
        $seeder->run();
    }

    /**
     * `down()` vide : retirer les permissions rendrait l'application
     * inutilisable, ce qui est pire que l'état d'avant migration.
     */
    public function down(): void {}
};
