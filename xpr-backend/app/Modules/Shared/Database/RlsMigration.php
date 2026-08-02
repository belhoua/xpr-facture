<?php

declare(strict_types=1);

namespace App\Modules\Shared\Database;

use Illuminate\Support\Facades\DB;

/**
 * Applique la Row Level Security standard d'une table tenant.
 *
 * NULLIF est impératif : après un SET LOCAL commité, la GUC app.company_id
 * retombe sur '' (pas NULL) et ''::uuid casserait toute requête suivante
 * de la connexion. Bug reproduit et corrigé au cadrage (docs/architecture).
 */
final class RlsMigration
{
    public static function apply(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
              USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
              WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
        SQL);
    }

    /**
     * Variante pour les tables qui mêlent lignes GLOBALES (company_id NULL) et
     * lignes de société : `settings`, `tax_rates`, `audit_logs`.
     *
     * Lecture : la société voit ses lignes ET le référentiel global — c'est
     * exactement le mécanisme de fallback « défaut plateforme surchargé par la
     * société ». Une société ne voit jamais les lignes d'une autre.
     *
     * Écriture : la chaîne applicative ne produit jamais de ligne globale — le
     * trait BelongsToCompany remplit toujours company_id. Le WITH CHECK laisse
     * néanmoins passer NULL parce que FORCE ROW LEVEL SECURITY s'applique aussi
     * au propriétaire de la table : sans cette tolérance, les seeders de
     * référentiel ne pourraient plus insérer le catalogue standard.
     */
    public static function applyWithGlobalRows(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
              USING (
                company_id IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid
              )
              WITH CHECK (
                company_id IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid
              )
        SQL);
    }

    /**
     * Retire FORCE : les policies cessent de s'appliquer au PROPRIÉTAIRE de la
     * table, et continuent de s'appliquer à tout autre rôle.
     *
     * Conséquence directe : si l'application se connecte avec le rôle
     * propriétaire — c'est le cas par défaut chez les hébergeurs gérés, dont
     * Neon avec `neondb_owner` — la RLS ne la contraint plus du tout. Il ne
     * reste que le scope Eloquent (BelongsToCompany) : l'isolation applicative
     * tient, la défense en profondeur exigée par CLAUDE.md §5.5 disparaît.
     *
     * La sortie durable n'est pas là : c'est un rôle applicatif dédié, non
     * propriétaire et sans BYPASSRLS (`xpr_app` en local), qui rétablit les
     * deux lignes de défense sans rien désactiver.
     */
    public static function unforce(string $table): void
    {
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
    }

    public static function force(string $table): void
    {
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    /**
     * Tables portant une policy tenant, dans l'ordre des migrations qui les
     * créent. Source unique pour les bascules globales de FORCE.
     *
     * @return list<string>
     */
    public static function protectedTables(): array
    {
        return [
            'invoices',
            'cash_movements',
            'admin_notes',
            'exchange_rates',
            'tax_rates',
            'fiscal_years',
            'sequences',
            'settings',
            'files',
            'idempotency_keys',
            'partners',
            'categories',
            'products',
            'document_items',
        ];
    }

    public static function drop(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
