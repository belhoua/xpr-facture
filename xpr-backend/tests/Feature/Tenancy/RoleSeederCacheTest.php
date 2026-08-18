<?php

declare(strict_types=1);

use App\Modules\Tenancy\Enums\Permission as PermissionEnum;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * `RoleSeeder` face à un CACHE DE PERMISSIONS PÉRIMÉ.
 *
 * ── Le défaut que ce fichier verrouille ──────────────────────────────────
 *
 * `Permission::findOrCreate()` ne lit pas la base : il passe par
 * `PermissionRegistrar::getPermissions()`, donc par le cache, qui mémorise des
 * objets Permission avec leurs identifiants AUTO-INCRÉMENTÉS. Le store étant
 * partagé (Redis en dehors des tests), un cache peuplé depuis une autre base
 * faisait croire au seeder que la permission existait : il ne la créait pas, et
 * rendait un identifiant étranger que `syncPermissions` insérait dans
 * `role_has_permissions` — que PostgreSQL refusait.
 *
 *     Key (permission_id)=(36) is not present in table "permissions"
 *
 * C'est ce qui a fait échouer `sync_project_permissions` sur Neon le
 * 2026-08-18. Le seeder vide désormais le cache AVANT de lire.
 */

/**
 * Reproduit l'état : le cache connaît une permission que la base n'a plus.
 *
 * L'ordre est ce qui compte — peupler le cache, PUIS supprimer la ligne. C'est
 * exactement la position d'un poste dont le cache vient d'une base locale et
 * qui pointe ensuite sur une base neuve.
 */
function poisonPermissionCache(string $name): int
{
    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId(null);
    $registrar->forgetCachedPermissions();

    /** @var object{id: int} $permission */
    $permission = DB::table('permissions')->where('name', $name)->firstOrFail();

    // Peuple le cache avec l'identifiant actuel…
    $registrar->getPermissions();

    // … que la base perd juste après.
    DB::table('role_has_permissions')->where('permission_id', $permission->id)->delete();
    DB::table('permissions')->where('id', $permission->id)->delete();

    return (int) $permission->id;
}

it('recrée une permission que seul le cache croyait présente', function (): void {
    $staleId = poisonPermissionCache('projects.view');

    expect(DB::table('permissions')->where('name', 'projects.view')->exists())->toBeFalse();

    // Sans le vidage préalable, cet appel levait une violation de clé
    // étrangère au lieu de recréer la permission.
    (new RoleSeeder)->run();

    /** @var object{id: int} $recreated */
    $recreated = DB::table('permissions')->where('name', 'projects.view')->firstOrFail();

    // L'identifiant est NEUF : c'est la preuve que le seeder est allé en base
    // plutôt que de faire confiance au cache.
    expect($recreated->id)->not->toBe($staleId);
});

it('ne laisse aucune ligne pivot orpheline après une resynchronisation', function (): void {
    poisonPermissionCache('projects.create');

    (new RoleSeeder)->run();

    $orphans = DB::selectOne(
        'SELECT count(*) AS n FROM role_has_permissions rhp
         LEFT JOIN permissions p ON p.id = rhp.permission_id
         WHERE p.id IS NULL',
    );

    expect((int) $orphans->n)->toBe(0);
});

it('rend au propriétaire la TOTALITÉ de la matrice', function (): void {
    poisonPermissionCache('services.manage');

    (new RoleSeeder)->run();

    /** @var object{id: int} $owner */
    $owner = DB::table('roles')->where('name', 'owner')->firstOrFail();

    // `owner` détient `Permission::cases()` en entier : le compte des droits
    // attribués doit suivre l'enum, et non ce qui restait après l'incident.
    expect(DB::table('role_has_permissions')->where('role_id', $owner->id)->count())
        ->toBe(count(PermissionEnum::values()));
});

it('reste rejouable sans rien dupliquer', function (): void {
    (new RoleSeeder)->run();
    (new RoleSeeder)->run();

    expect(DB::table('permissions')->count())->toBe(count(PermissionEnum::values()));
});
