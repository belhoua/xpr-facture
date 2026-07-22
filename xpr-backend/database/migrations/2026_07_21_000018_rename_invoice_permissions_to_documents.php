<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Renomme les permissions `invoices.*` en `documents.*` et resynchronise la
 * matrice des rôles (catalogue + émission des documents).
 *
 * Le RENOMMAGE plutôt que la suppression / recréation est délibéré :
 * `role_has_permissions` et `model_has_permissions` référencent la permission
 * par son ID. Renommer la ligne conserve toutes les attributions existantes ;
 * la supprimer les emporterait en cascade, et un owner d'une base existante se
 * retrouverait sans droit sur ses propres factures le temps que le seeder
 * repasse.
 *
 * Même raison d'être que 000012_sync_roles_and_permissions : une base dont la
 * table `permissions` ne reflète pas l'enum refuse TOUT à TOUS, y compris aux
 * owners, puisque chaque route porte sa permission (§10).
 */
return new class extends Migration
{
    /** Ancien nom => nouveau nom. */
    private const RENAMES = [
        'invoices.view' => 'documents.view',
        'invoices.create' => 'documents.create',
        'invoices.update' => 'documents.update',
        'invoices.delete' => 'documents.delete',
        'invoices.cancel' => 'documents.cancel',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            // Si la cible existe déjà (migration rejouée sur une base
            // partiellement à jour), on ne crée pas de doublon : l'index unique
            // (name, guard_name) le refuserait de toute façon.
            $targetExists = DB::table('permissions')->where('name', $new)->exists();

            if ($targetExists) {
                DB::table('permissions')->where('name', $old)->delete();

                continue;
            }

            DB::table('permissions')->where('name', $old)->update(['name' => $new]);
        }

        // Purge du cache Spatie AVANT le seeder, et pas seulement après : les
        // renommages ci-dessus sont passés en SQL direct, le registre garde
        // donc en mémoire les anciens noms. `findOrCreate('documents.view')`
        // ne les y trouverait pas, tenterait un INSERT, et heurterait l'index
        // unique (name, guard_name) sur la ligne qu'on vient de renommer.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Crée `documents.issue` et les `catalog.*`, puis réapplique la matrice.
        $seeder = new RoleSeeder;
        $seeder->setContainer(app());
        $seeder->run();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Vide, pour la même raison que 000012 : retirer des droits casse l'app. */
    public function down(): void {}
};
