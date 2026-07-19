<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles par défaut (arbitrage cadrage Q4) : globaux (company_id NULL dans
 * roles), assignés PAR société via le mode teams de Spatie. Les permissions
 * fines arrivent avec chaque module ; les rôles existent dès le socle pour
 * que l'inscription puisse attribuer "owner".
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Contexte team neutre : on crée des rôles globaux, pas scopés.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach (['owner', 'admin', 'accountant', 'sales', 'viewer'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
