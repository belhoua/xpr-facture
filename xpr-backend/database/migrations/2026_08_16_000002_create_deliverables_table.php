<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Livrable remis au client : notice technique, rapport d'avancement,
 * procès-verbal.
 *
 * ── Ce que la table enregistre, et ce qu'elle n'enregistre pas ─────────────
 *
 * Elle date une REMISE, elle ne stocke pas le document remis. Le fichier, s'il
 * doit être conservé, relève du module Files et de son disque privé ; ici on
 * répond à « qu'a-t-on transmis à ce client, et quand ? » — la question qui se
 * pose quand il affirme n'avoir rien reçu.
 *
 * Le titre est donc LIBRE et non un enum : chaque métier a sa nomenclature de
 * livrables, et la figer ferait refuser un intitulé légitime dès le premier
 * client dont le vocabulaire diffère.
 *
 * `company_id` y figure malgré la remontée possible par `project_id` : le
 * global scope `BelongsToCompany` et la policy RLS filtrent sur cette colonne,
 * et une jointure ne saurait pas s'y substituer (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliverables', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Cascade : un livrable n'a aucun sens hors du projet qui l'a
            // produit. Le projet étant en soft delete, la cascade ne se
            // déclenche qu'à l'effacement dur.
            $table->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();

            $table->string('title');
            $table->date('delivery_date');
            $table->text('notes')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // La lecture de cette table, c'est la liste datée des remises d'UN
            // projet ; le second index sert l'écran « qu'a-t-on remis ce
            // mois-ci ».
            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'delivery_date']);
        });

        RlsMigration::apply('deliverables');
    }

    public function down(): void
    {
        RlsMigration::drop('deliverables');
        Schema::dropIfExists('deliverables');
    }
};
