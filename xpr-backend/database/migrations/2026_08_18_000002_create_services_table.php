<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel des SERVICES de la société.
 *
 * ── Distinct du catalogue, et c'est la demande ────────────────────────────
 *
 * Un « service » du catalogue est un ARTICLE VENDU : il porte un prix, une
 * unité, un taux de TVA, et se pose sur une ligne de facture (`products` avec
 * `type = 'service'`). Ce référentiel-ci désigne autre chose — la nature de la
 * mission qu'un projet recouvre — et n'a rien de tarifaire.
 *
 * Les deux ne pouvaient donc pas partager une table : y loger cette entité
 * aurait obligé à laisser NULLES toutes les colonnes de prix, puis à écarter
 * ces lignes de chaque écran du catalogue et de chaque déroulant de
 * facturation. Deux tables valent mieux qu'un type de plus dont on passe son
 * temps à se défendre (arbitrage de l'exploitant, 2026-08-18).
 *
 * ⚠️ Il en résulte DEUX notions nommées « service » dans le produit, l'article
 * catalogue de l'écran `/services` et ce référentiel. Le code les distingue par
 * leur table ; l'interface, elle, n'a qu'un mot pour les deux.
 *
 * ── Multi-tenant, comme toute table métier ───────────────────────────────
 *
 * `company_id` NOT NULL, scope Eloquent et RLS : les services d'un cabinet ne
 * regardent pas ses confrères (§5). Ce n'est pas un référentiel partagé comme
 * `tax_rates`, dont le catalogue standard vaut pour tout le Maroc.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->timestampsTz();
            // Soft delete comme le reste du dépôt (§7) : un service retiré du
            // référentiel reste référencé par les projets passés, dont l'
            // historique doit rester lisible.
            $table->softDeletesTz();

            // Le déroulant lit par société, par ordre alphabétique.
            $table->index(['company_id', 'name']);
        });

        // Deux services de MÊME NOM dans une société seraient indiscernables
        // dans un déroulant. Index PARTIEL : un nom se libère à l'archivage,
        // sinon une erreur de saisie corrigée par un archivage interdirait
        // définitivement de resaisir le bon nom.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX services_company_name_unique
              ON services (company_id, lower(btrim(name)))
              WHERE deleted_at IS NULL
        SQL);

        RlsMigration::apply('services');
    }

    public function down(): void
    {
        RlsMigration::drop('services');
        Schema::dropIfExists('services');
    }
};
