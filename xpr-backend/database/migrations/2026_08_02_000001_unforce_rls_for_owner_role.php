<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;

/**
 * Retire FORCE ROW LEVEL SECURITY sur les tables tenant.
 *
 * MESURE DE DÉBLOCAGE, DEMANDÉE EXPLICITEMENT — pas une évolution de
 * l'architecture. Ce qu'elle change, en clair :
 *
 *   - les policies restent EN PLACE et s'appliquent à tout rôle qui n'est pas
 *     propriétaire des tables ;
 *   - elles cessent de s'appliquer au propriétaire. Sur Neon l'application se
 *     connecte en `neondb_owner`, qui possède les tables : la RLS ne la
 *     contraint donc plus du tout ;
 *   - l'isolation multi-tenant repose alors sur le SEUL scope Eloquent
 *     (BelongsToCompany). C'est la première ligne de défense, elle est
 *     testée ; c'est la seconde, exigée par CLAUDE.md §5.5, qui saute.
 *
 * Un oubli de scope — une requête en DB::table(), un withoutGlobalScopes()
 * laissé là, un job sans TenantAware — n'est plus rattrapé par la base. C'est
 * précisément le scénario contre lequel la RLS avait été mise.
 *
 * RETOUR ARRIÈRE : `php artisan migrate:rollback --step=1`, qui remet FORCE.
 *
 * SORTIE DURABLE : connecter l'application avec un rôle dédié, NON
 * propriétaire et sans BYPASSRLS — l'équivalent Neon de `xpr_app`, déjà créé
 * en local par xpr-infrastructure/docker/postgres/init. Les policies
 * s'appliquent alors sans que FORCE soit nécessaire, et les deux lignes de
 * défense sont rétablies. C'est le reliquat P0-09 (cf. CLAUDE.md §15).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (RlsMigration::protectedTables() as $table) {
            RlsMigration::unforce($table);
        }
    }

    public function down(): void
    {
        foreach (RlsMigration::protectedTables() as $table) {
            RlsMigration::force($table);
        }
    }
};
