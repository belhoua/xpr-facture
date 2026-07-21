<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Tenancy\Enums\Permission as PermissionEnum;
use App\Modules\Tenancy\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles et permissions par défaut (arbitrage cadrage Q4).
 *
 * Les rôles sont GLOBAUX (company_id NULL dans `roles`) mais ATTRIBUÉS par
 * société via le mode teams : `model_has_roles` porte le company_id. Être owner
 * chez A ne donne donc aucun droit chez B — c'est ce que vérifie
 * tests/Feature/Tenancy/RolePerCompanyTest.php.
 *
 * Idempotent : `db:seed` rejoué ne duplique rien et resynchronise la matrice
 * si elle a évolué dans l'enum Role.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Contexte team neutre : on crée des rôles globaux, pas scopés à une
        // société. Sans cela, findOrCreate en créerait un jeu par société.
        $registrar->setPermissionsTeamId(null);

        foreach (PermissionEnum::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (RoleEnum::cases() as $case) {
            $role = Role::findOrCreate($case->value, 'web');

            // syncPermissions et non givePermissionTo : la matrice de l'enum
            // fait autorité, y compris pour RETIRER un droit qu'on n'accorde
            // plus. Un seeder qui ne fait qu'ajouter laisserait traîner
            // d'anciens privilèges après un durcissement.
            $role->syncPermissions($case->permissionValues());
        }

        $registrar->forgetCachedPermissions();
    }
}
