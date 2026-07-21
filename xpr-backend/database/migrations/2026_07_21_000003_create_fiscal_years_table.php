<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exercices comptables. Décision produit du 2026-07-21 : année civile PAR
 * DÉFAUT — l'exercice créé à l'inscription va du 1er janvier au 31 décembre —
 * mais les bornes restent des colonnes, donc une société à exercice décalé
 * (1er juillet → 30 juin) se configure sans changement de schéma.
 *
 * L'exercice est le périmètre de remise à zéro de la numérotation : c'est lui
 * qui fait repartir FAC-2026-0001 à 0001 le 1er janvier (cf. table sequences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_years', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20)->comment("'2026', ou '2026-2027' si exercice décalé");
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('open');
            $table->timestampsTz();

            $table->unique(['company_id', 'label'], 'fiscal_years_label_unique');
            $table->index(['company_id', 'starts_on']);
        });

        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_dates_check CHECK (ends_on > starts_on)');
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years
              ADD CONSTRAINT fiscal_years_status_check
              CHECK (status IN ('open','closing','closed'))
        SQL);

        // Deux exercices d'une même société ne peuvent pas se chevaucher : une
        // date d'émission doit désigner UN exercice, sans ambiguïté, sinon la
        // séquence à utiliser devient indéterminée. Contrainte d'exclusion GiST
        // (btree_gist est créée par la migration 2026_07_19_000001) : la base
        // refuse le chevauchement, on ne compte pas sur une vérification
        // applicative qui perdrait la course en concurrence.
        DB::statement(<<<'SQL'
            ALTER TABLE fiscal_years
              ADD CONSTRAINT fiscal_years_no_overlap
              EXCLUDE USING gist (
                company_id WITH =,
                daterange(starts_on, ends_on, '[]') WITH &&
              )
        SQL);

        RlsMigration::apply('fiscal_years');
    }

    public function down(): void
    {
        RlsMigration::drop('fiscal_years');
        Schema::dropIfExists('fiscal_years');
    }
};
