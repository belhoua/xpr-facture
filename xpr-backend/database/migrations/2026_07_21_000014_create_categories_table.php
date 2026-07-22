<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catégories du catalogue : le classement des produits et services.
 *
 * Volontairement PLATE (pas d'arborescence parent/enfant) : la hiérarchie ne
 * sert à rien tant qu'aucun écran ne l'exploite, et elle coûte cher — requêtes
 * récursives, gestion des cycles, agrégats qui doivent remonter l'arbre. Le
 * jour où un état des ventes par famille l'exigera, une colonne `parent_id`
 * s'ajoutera sans casser l'existant.
 *
 * `color` sert au repérage visuel dans les listes denses (§11) : un catalogue
 * de 300 lignes se lit à la pastille, pas au libellé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            // Teinte hexadécimale (#RRGGBB), choisie dans une palette fermée
            // côté interface — la contrainte ci-dessous n'en vérifie que la forme.
            $table->char('color', 7)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'is_active']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_color_format_check CHECK (color ~ '^#[0-9A-Fa-f]{6}$')");

        // Deux catégories homonymes dans la même société sont une erreur de
        // saisie, pas un cas métier. Index PARTIEL : une catégorie archivée
        // libère son nom.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX categories_company_name_unique
              ON categories (company_id, lower(name))
              WHERE deleted_at IS NULL
        SQL);

        RlsMigration::apply('categories');
    }

    public function down(): void
    {
        RlsMigration::drop('categories');
        Schema::dropIfExists('categories');
    }
};
