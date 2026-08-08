<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée `conventions.*` et `deposits.*` puis réapplique la matrice des rôles.
 *
 * Même raison d'être que `000012_sync_roles_and_permissions` : chaque route
 * porte sa permission (§10), donc une base dont la table `permissions` ne
 * reflète pas l'enum refuse l'écran à TOUT LE MONDE, owners compris. Le seeder
 * est idempotent — `findOrCreate` puis `syncPermissions` — et se rejoue sans
 * dupliquer ni perdre d'attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        $seeder = new RoleSeeder;
        $seeder->setContainer(app());
        $seeder->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Vide, pour la même raison que 000012 et 000018 : retirer des permissions
     * casserait une application en service, et la migration n'a rien créé qui ne
     * puisse être recréé par le seeder.
     */
    public function down(): void {}
};
