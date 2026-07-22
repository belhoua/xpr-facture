<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lignes de document. C'est ici que vit la règle fiscale la plus structurante
 * du §3 : **le taux de TVA est stocké par ligne, jamais globalement**.
 *
 * Deux colonnes pour la TVA, et ce n'est pas une redondance :
 *  - `tax_rate_id` — la RÉFÉRENCE, pour naviguer et regrouper (« mes lignes
 *    à 20 % »), qui peut pointer vers un taux plus tard désactivé ;
 *  - `tax_rate` — le TAUX APPLIQUÉ, figé à la saisie. C'est lui qui fait foi
 *    dans le récapitulatif de TVA et dans la déclaration. Si la DGI change le
 *    taux d'un secteur demain, les documents déjà émis doivent continuer de
 *    porter l'ancien. Une simple FK réécrirait l'histoire fiscale.
 *
 * Même logique pour `label`, `unit` et `unit_price_cents` : instantanés du
 * produit au moment de la saisie. Le catalogue peut ensuite être renommé ou
 * revalorisé sans altérer un document déjà émis (§3, immuabilité).
 *
 * `product_id` reste facultatif : la SAISIE LIBRE est un cas normal, pas une
 * dérogation. Une prestation ponctuelle n'a aucune raison d'entrer au catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_items', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            // company_id sur la ligne alors que le document le porte déjà : la
            // RLS PostgreSQL est une policy PAR TABLE (§5.5). Sans cette
            // colonne, `document_items` n'aurait aucune seconde ligne de
            // défense et un SELECT direct traverserait les sociétés.
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // CASCADE : une ligne n'a aucune existence hors de son document.
            // La protection porte sur le document (immuable une fois validé),
            // pas sur la ligne.
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();

            // RESTRICT : un produit référencé par un document émis ne peut pas
            // être supprimé physiquement. L'archivage (soft delete) reste ouvert.
            $table->foreignUuid('product_id')->nullable()->constrained('products')->restrictOnDelete();

            // Rang d'affichage. L'ordre des lignes est une donnée du document —
            // s'appuyer sur l'ordre d'insertion ferait dépendre le rendu du
            // plan d'exécution de PostgreSQL.
            $table->smallInteger('position')->default(0);

            $table->string('label');
            $table->text('description')->nullable();

            // numeric exact et non float : 0,1 + 0,2 en binaire ne vaut pas 0,3,
            // et une facture ne se discute pas à l'arrondi près (§7).
            // 3 décimales : suffisant pour des heures, des kilos ou des mètres.
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->default('unité');
            $table->bigInteger('unit_price_cents')->default(0);

            // Remise de LIGNE, en pourcentage. Au niveau de la ligne et non du
            // document : c'est la granularité qu'impose le récapitulatif de TVA
            // par taux, une remise globale devant de toute façon être ventilée
            // sur chaque taux pour être déclarable.
            $table->decimal('discount_percent', 5, 2)->default(0);

            $table->foreignUuid('tax_rate_id')->nullable()->constrained('tax_rates')->restrictOnDelete();
            // Taux figé, en pourcentage (20.00). NULL impossible : une ligne
            // sans TVA porte 0.00, ce qui est une information, alors que NULL
            // serait une absence de décision.
            $table->decimal('tax_rate', 5, 2)->default(0);

            // Montants calculés par DocumentCalculator, en centimes.
            // `subtotal_cents` = HT APRÈS remise — c'est la base d'imposition.
            $table->bigInteger('subtotal_cents')->default(0);
            $table->bigInteger('discount_cents')->default(0);
            $table->bigInteger('tax_cents')->default(0);
            $table->bigInteger('total_cents')->default(0);

            $table->timestampsTz();

            // Pas de soft delete : retirer une ligne d'un BROUILLON est une
            // correction de saisie, pas un événement à conserver. Et un
            // document validé ne se modifie plus — ses lignes non plus.

            $table->index(['company_id', 'document_id']);
            $table->index(['company_id', 'product_id']);
            // Le récapitulatif de TVA par taux (§3) groupe sur cette colonne.
            $table->index(['company_id', 'tax_rate_id']);
        });

        DB::statement('ALTER TABLE document_items ADD CONSTRAINT document_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE document_items ADD CONSTRAINT document_items_price_check CHECK (unit_price_cents >= 0)');
        DB::statement('ALTER TABLE document_items ADD CONSTRAINT document_items_discount_check CHECK (discount_percent >= 0 AND discount_percent <= 100)');
        DB::statement('ALTER TABLE document_items ADD CONSTRAINT document_items_tax_rate_check CHECK (tax_rate >= 0 AND tax_rate <= 100)');

        // Deux lignes ne peuvent pas occuper le même rang dans un document :
        // l'ordre serait alors non déterministe, et le PDF ne reproduirait pas
        // ce que l'utilisateur a saisi.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX document_items_position_unique
              ON document_items (document_id, position)
        SQL);

        RlsMigration::apply('document_items');
    }

    public function down(): void
    {
        RlsMigration::drop('document_items');
        Schema::dropIfExists('document_items');
    }
};
