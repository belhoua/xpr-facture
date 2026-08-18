<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projet suivi pour un client : l'avancement d'une mission, du lancement à sa
 * clôture.
 *
 * ── Pourquoi une table et non un type de `documents` de plus ───────────────
 *
 * Un projet n'est pas une pièce. Il n'a ni numéro de séquence, ni montant, ni
 * exercice fiscal, et rien de ce que le §3 impose aux documents commerciaux ne
 * s'y applique — il n'est opposable à personne. L'y loger aurait ajouté un
 * neuvième `type` dont TOUTES les colonnes fiscales seraient restées nulles, et
 * étendu à une entité de suivi les règles d'immuabilité qui n'ont de sens que
 * pour une facture.
 *
 * ── `partner_id` OBLIGATOIRE ───────────────────────────────────────────────
 *
 * Contrairement aux conventions, où il est nullable : l'écran d'avancement
 * filtre par client, et un projet sans client y serait invisible — donc absent
 * des listes de celui pour qui on le mène. Même raisonnement que le
 * `partnerId` requis d'une situation.
 *
 * `restrictOnDelete` et non `nullOnDelete` : perdre le client d'un projet en
 * cours le rendrait orphelin sur un écran dont c'est le premier filtre. Les
 * tiers étant en soft delete, le cas ne se présente qu'à l'effacement dur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            $table->foreignUuid('partner_id')->constrained('partners')->restrictOnDelete();

            $table->string('title');
            $table->string('status', 20)->default('in_progress');

            // Pourcentage ENTIER, pas décimal : un avancement de chantier
            // s'annonce au point près, et une décimale n'ajouterait qu'une
            // fausse précision sur une donnée déclarative.
            $table->smallInteger('progress_percentage')->default(0);

            $table->text('description')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // L'écran liste du plus récent au plus ancien, filtré par client ou
            // par statut. Les trois index composites portent `company_id` en
            // tête, comme toute lecture sous le scope tenant (§7).
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'partner_id']);
            $table->index(['company_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE projects
              ADD CONSTRAINT projects_status_check
              CHECK (status IN ('in_progress','completed','monitoring','canceled'))
        SQL);

        // La borne 0–100 tient en base et pas seulement dans la FormRequest :
        // elle vaut aussi pour les seeders, les imports et la console, et un
        // avancement de 140 % n'a de sens nulle part.
        DB::statement(<<<'SQL'
            ALTER TABLE projects
              ADD CONSTRAINT projects_progress_range_check
              CHECK (progress_percentage BETWEEN 0 AND 100)
        SQL);

        RlsMigration::apply('projects');
    }

    public function down(): void
    {
        RlsMigration::drop('projects');
        Schema::dropIfExists('projects');
    }
};
