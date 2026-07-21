<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paramètres clé/valeur (P0-11). `company_id` NULL = valeur par défaut de la
 * plateforme, surchargée par la ligne de la société quand elle existe : la
 * lecture cherche d'abord le périmètre société, puis retombe sur le global.
 *
 * `value` en jsonb, pas en texte : un paramètre peut être un booléen, un
 * nombre, une liste ou un objet, et on veut pouvoir l'indexer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 100);
            $table->jsonb('value');
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        // NULLS NOT DISTINCT : sans lui, PostgreSQL considère deux NULL comme
        // distincts et autoriserait plusieurs lignes globales pour la même clé.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX settings_scope_key_unique
              ON settings (company_id, key)
              NULLS NOT DISTINCT
        SQL);

        RlsMigration::applyWithGlobalRows('settings');
    }

    public function down(): void
    {
        RlsMigration::drop('settings');
        Schema::dropIfExists('settings');
    }
};
