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

    public static function drop(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
