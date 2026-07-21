<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Un numéro de facture ne peut être porté que par UNE facture, par société.
 *
 * La table a été créée sans cette garantie : la numérotation reposait alors sur
 * un MAX(number) + 1 applicatif, qui pouvait attribuer deux fois le même numéro
 * en concurrence sans que la base ne s'y oppose. Le compteur vit désormais dans
 * `sequences` ; cet index est le filet qui rend le doublon IMPOSSIBLE plutôt
 * qu'improbable — « sans réutilisation » est une exigence fiscale (§3), pas une
 * intention.
 *
 * Index PARTIEL, pour deux raisons :
 *  - les brouillons n'ont pas de numéro, et plusieurs NULL doivent coexister ;
 *  - une facture soft-deletée ne doit pas bloquer son propre numéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX invoices_company_number_unique
              ON invoices (company_id, number)
              WHERE number IS NOT NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_company_number_unique');
    }
};
