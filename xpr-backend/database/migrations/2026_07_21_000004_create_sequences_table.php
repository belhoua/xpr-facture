<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numérotation continue, sans trou et sans réutilisation (§3).
 *
 * Une ligne par (société × type de document × exercice) : inclure l'exercice
 * dans la clé, c'est ce qui implémente la REMISE À ZÉRO ANNUELLE décidée le
 * 2026-07-21 — au 1er janvier, le nouvel exercice n'a pas encore de ligne, elle
 * est créée à `next_number = 1` et FAC-2026-0001 repart à 0001.
 *
 * Le compteur n'est PAS une SEQUENCE PostgreSQL : une sequence est
 * non-transactionnelle, un rollback laisserait un trou définitif dans la
 * numérotation, ce que l'administration fiscale n'admet pas. On prend donc un
 * verrou de ligne (SELECT … FOR UPDATE) dans la transaction qui valide le
 * document : si elle échoue, le numéro n'est pas consommé.
 *
 * Les types couvrent le moteur de documents unique arbitré le 2026-07-21.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            // RESTRICT : supprimer un exercice qui a servi à numéroter des
            // documents effacerait la preuve de la continuité de la séquence.
            $table->foreignUuid('fiscal_year_id')->constrained()->restrictOnDelete();
            $table->string('document_type', 20);
            $table->string('format', 50)->comment('FAC-{YYYY}-{0000}');
            $table->integer('next_number')->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'document_type', 'fiscal_year_id'], 'sequences_unique');
        });

        DB::statement('ALTER TABLE sequences ADD CONSTRAINT sequences_positive CHECK (next_number >= 1)');
        DB::statement(<<<'SQL'
            ALTER TABLE sequences
              ADD CONSTRAINT sequences_doc_type_check
              CHECK (document_type IN (
                'invoice','quote','proforma','purchase_order',
                'delivery_note','shipping_slip','credit_note','purchase_invoice'
              ))
        SQL);

        RlsMigration::apply('sequences');
    }

    public function down(): void
    {
        RlsMigration::drop('sequences');
        Schema::dropIfExists('sequences');
    }
};
