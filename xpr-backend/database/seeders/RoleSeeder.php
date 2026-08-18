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
 *
 * Rejouable sur N'IMPORTE QUELLE base, y compris depuis un poste dont le cache
 * de permissions a été peuplé par une autre — c'est ce que garantit le vidage
 * en tête de `run()`, et ce qui manquait avant le 2026-08-18.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // Contexte team neutre : on crée des rôles globaux, pas scopés à une
        // société. Sans cela, findOrCreate en créerait un jeu par société.
        $registrar->setPermissionsTeamId(null);

        // ── Le cache est vidé AVANT, et c'est le cœur de ce seeder ─────────
        //
        // `Permission::findOrCreate()` ne consulte PAS la base : il passe par
        // `PermissionRegistrar::getPermissions()`, qui sert le cache — un
        // ensemble d'objets Permission avec leurs identifiants AUTO-INCRÉMENTÉS.
        //
        // Ces identifiants n'ont de sens que pour la base qui les a produits.
        // Le store de cache étant partagé (Redis, cf. config/permission.php),
        // un cache peuplé depuis une autre base — un environnement local, un
        // déploiement antérieur — fait croire à `findOrCreate` que la
        // permission existe déjà : il ne la crée pas, et rend un objet portant
        // un identifiant étranger. `syncPermissions` insère alors ce numéro
        // dans `role_has_permissions`, et PostgreSQL refuse :
        //
        //     Key (permission_id)=(36) is not present in table "permissions"
        //
        // Vider après la synchronisation, comme on le faisait, arrive une
        // opération trop tard : le mal est fait à la première lecture. C'est
        // ce qui a fait échouer `sync_project_permissions` sur Neon le
        // 2026-08-18.
        $registrar->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Second vidage : les créations ci-dessus ont peuplé la table, et la
        // matrice qui suit doit résoudre chaque nom sur l'identifiant RÉEL que
        // cette base vient d'attribuer.
        $registrar->forgetCachedPermissions();

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
