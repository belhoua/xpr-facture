<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taux de TVA. Jamais codés en dur : la réglementation marocaine évolue (§3).
 *
 * `company_id` NULLABLE, décision produit du 2026-07-21 : les lignes à NULL
 * forment le CATALOGUE STANDARD marocain (0 / 7 / 10 / 14 / 20 %, exonéré,
 * hors champ), partagé et lisible par toutes les sociétés ; une société ajoute
 * les siens en créant des lignes portant son company_id. Écart au schéma de
 * cadrage, qui prévoyait une copie des taux par société.
 *
 * Ce partage n'expose PAS l'historique fiscal à une modification rétroactive :
 * §3 impose que la ligne de document stocke le taux appliqué, pas seulement une
 * FK. Changer le taux standard n'altère donc aucune facture déjà émise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            // NULL = catalogue standard partagé (cf. en-tête)
            $table->foreignUuid('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label_fr', 80);
            $table->string('label_ar', 80);
            // Pourcentage exact (20.00), pas un montant. numeric, jamais float.
            $table->decimal('rate', 5, 2);
            $table->string('kind', 12)->default('standard');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
        });

        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_range CHECK (rate >= 0 AND rate <= 100)');
        DB::statement(<<<'SQL'
            ALTER TABLE tax_rates
              ADD CONSTRAINT tax_rates_kind_check
              CHECK (kind IN ('standard','exonere','hors_champ'))
        SQL);

        // Un seul taux par défaut par périmètre. Index PARTIEL : la contrainte
        // ne porte que sur les lignes marquées par défaut et actives, les
        // autres sont libres. NULLS NOT DISTINCT fait que le catalogue global
        // (company_id NULL) compte comme un périmètre à part entière.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX tax_rates_single_default
              ON tax_rates (company_id)
              NULLS NOT DISTINCT
              WHERE is_default AND deleted_at IS NULL
        SQL);

        RlsMigration::applyWithGlobalRows('tax_rates');
    }

    public function down(): void
    {
        RlsMigration::drop('tax_rates');
        Schema::dropIfExists('tax_rates');
    }
};
