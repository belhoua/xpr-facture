<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un projet au SERVICE dont il relève.
 *
 * NULLABLE, et ce n'est pas un compromis : le référentiel naît vide, les
 * projets déjà en base n'ont aucun service à porter, et rien ne permettrait de
 * le deviner. Un projet peut aussi légitimement n'en relever d'aucun.
 *
 * `nullOnDelete` : le référentiel est du CLASSEMENT, le projet est le suivi
 * d'une mission réelle. L'effacement dur d'un service ne doit ni bloquer ni
 * emporter les projets qui s'y rattachaient — ils perdent leur classement, pas
 * leur existence. Le cas reste rare : `services` est en soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->foreignUuid('service_id')->nullable()->after('partner_id')
                ->constrained('services')->nullOnDelete();

            // `company_id` en tête comme toute lecture sous le scope tenant (§7).
            $table->index(['company_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'service_id']);
            $table->dropConstrainedForeignId('service_id');
        });
    }
};
