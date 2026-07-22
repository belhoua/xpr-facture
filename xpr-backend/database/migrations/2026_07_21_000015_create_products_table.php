<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue : biens ET services dans une seule table, discriminés par `type`.
 *
 * Même raisonnement que pour `partners` : les deux portent exactement les mêmes
 * attributs commerciaux (libellé, prix unitaire, unité, TVA applicable) et ne
 * divergent que sur un point — un bien peut être suivi en stock, un service
 * jamais. Ce point tient dans un booléen, pas dans une seconde table.
 *
 * `track_stock` est posé DÈS MAINTENANT, sans mouvement de stock associé :
 * c'est le drapeau que l'étape « Stocks » consommera. Le poser plus tard
 * imposerait de repasser sur tout le catalogue déjà saisi pour le qualifier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Une catégorie archivée ne doit pas emporter ses produits :
            // nullOnDelete plutôt que cascade. Le produit reste vendable.
            $table->foreignUuid('category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('type', 10)->default('service');
            // Référence interne / code-barres interne. Libre : chaque société a
            // sa codification, on ne l'impose pas (cf. `partners.code`).
            $table->string('reference', 40)->nullable();

            $table->string('name');
            $table->text('description')->nullable();

            // Unité de mesure en TEXTE LIBRE et non en ENUM contraint : « ml »,
            // « tonne », « m² », « forfait », « nuitée »… la liste réelle est
            // ouverte et varie par métier. L'interface propose les unités
            // usuelles, la base ne les impose pas. Aucune règle fiscale n'en
            // dépend — contrairement au taux de TVA, qui lui est référencé.
            $table->string('unit', 20)->default('unité');

            // Prix de VENTE hors taxes, en centimes (§7). Jamais de FLOAT.
            $table->bigInteger('unit_price_cents')->default(0);
            // Prix de REVIENT, facultatif : sert au calcul de marge et servira
            // de valorisation par défaut à l'étape Achats.
            $table->bigInteger('cost_price_cents')->nullable();
            $table->char('currency', 3)->default('MAD');

            // Taux de TVA par défaut de la ligne de document. RESTRICT : un taux
            // référencé par le catalogue ne disparaît pas silencieusement.
            // Nullable : un auto-entrepreneur sous seuil n'applique pas de TVA.
            $table->foreignUuid('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();

            $table->boolean('track_stock')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency')->references('code')->on('currencies');
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'category_id']);
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE products
              ADD CONSTRAINT products_type_check
              CHECK (type IN ('good','service'))
        SQL);

        // Un prix négatif n'est pas une remise : c'est une saisie fausse. La
        // remise se porte sur la LIGNE de document, jamais sur le catalogue.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_positive_check CHECK (unit_price_cents >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_cost_positive_check CHECK (cost_price_cents IS NULL OR cost_price_cents >= 0)');

        // Un service ne se stocke pas : la contrainte l'interdit en base plutôt
        // que de compter sur l'interface pour ne pas cocher la case.
        DB::statement(<<<'SQL'
            ALTER TABLE products
              ADD CONSTRAINT products_stock_goods_only_check
              CHECK (NOT track_stock OR type = 'good')
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX products_company_reference_unique
              ON products (company_id, reference)
              WHERE reference IS NOT NULL AND deleted_at IS NULL
        SQL);

        RlsMigration::apply('products');
    }

    public function down(): void
    {
        RlsMigration::drop('products');
        Schema::dropIfExists('products');
    }
};
