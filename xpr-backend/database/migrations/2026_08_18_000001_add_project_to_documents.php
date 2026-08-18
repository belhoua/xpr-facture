<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un document commercial au PROJET qu'il facture.
 *
 * ── Pourquoi ce lien manquait ─────────────────────────────────────────────
 *
 * L'avancement d'un projet et les pièces qui le facturent vivaient côte à côte
 * sans se connaître. On pouvait lire « chantier à 60 % » sur un écran et
 * « 180 000 MAD facturés » sur un autre, sans qu'aucune requête ne puisse dire
 * combien ce chantier-là avait rapporté. C'est la question que pose un
 * responsable d'affaire, et elle n'avait pas de réponse.
 *
 * ── NULLABLE, et ce n'est pas un compromis ────────────────────────────────
 *
 * La grande majorité des pièces ne relèvent d'aucun projet : une facture de
 * fournitures, un devis ponctuel. Rendre la colonne obligatoire aurait forcé à
 * inventer un projet pour chaque vente. Les documents déjà en base restent donc
 * à NULL — ils ne sont rattachés à rien, et rien ne permet de deviner à quoi.
 *
 * ── `nullOnDelete` et non `restrictOnDelete` ──────────────────────────────
 *
 * L'inverse du choix fait sur `projects.partner_id`, et pour une raison de
 * hiérarchie : un projet est une entité de SUIVI, une facture est une pièce
 * FISCALE. L'effacement dur d'un projet ne doit ni bloquer ni emporter une
 * facture qui, elle, reste opposable — elle perd son rattachement, pas son
 * existence. Le cas reste rare : `projects` est en soft delete.
 *
 * ── La cohérence client N'EST PAS exprimable ici ──────────────────────────
 *
 * Un document ne devrait porter que le projet de SON client. La contrainte
 * porterait sur `projects.partner_id`, une autre table : ni un CHECK ni une FK
 * ne savent l'écrire. Elle est donc tenue par `DocumentWriteService`, qui
 * refuse un projet dont le client n'est pas celui du document — et par le test
 * qui l'accompagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('project_id')->nullable()->after('partner_id')
                ->constrained('projects')->nullOnDelete();

            // L'écran « situations par client » filtre par projet, et les
            // totaux se recalculent sur ce filtre. `company_id` en tête comme
            // toute lecture sous le scope tenant (§7).
            $table->index(['company_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'project_id']);
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
