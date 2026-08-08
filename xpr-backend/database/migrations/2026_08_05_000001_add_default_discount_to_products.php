<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remise commerciale habituellement consentie sur un article du catalogue.
 *
 * C'est une VALEUR PAR DÉFAUT DE SAISIE, pas une règle de prix : elle est
 * recopiée dans `document_items.discount_percent` au moment où l'article est
 * posé sur une ligne, puis la ligne vit sa vie. Modifier la fiche article
 * n'altère donc aucun document déjà saisi — même raisonnement que pour
 * `tax_rate_id`, dont la ligne fige aussi une copie (§3, immuabilité).
 *
 * Le prix catalogue reste le prix plein. On ne l'ampute pas de la remise :
 * un état des ventes doit pouvoir distinguer le chiffre d'affaires brut de
 * l'effort commercial consenti, ce qu'un prix déjà remisé rend impossible.
 *
 * DECIMAL(5,2) et non un entier de centièmes : c'est le type déjà retenu par
 * `document_items.discount_percent`, et un pourcentage n'est pas un montant —
 * la règle « montants en centimes entiers » (§7) ne le concerne pas. Les deux
 * colonnes doivent partager le même type, sinon la recopie arrondirait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            // NOT NULL avec défaut à 0 plutôt que nullable : « pas de remise »
            // et « remise nulle » désignent la même chose commercialement, et
            // un NULL obligerait chaque appelant à le coalescer avant calcul.
            $table->decimal('default_discount_percent', 5, 2)->default(0)->after('cost_price_cents');
        });

        // Même borne que `document_items_discount_check` : une valeur que la
        // ligne de document refuserait n'a aucune raison d'être stockable ici.
        // Au-delà de 100 %, le vendeur paierait le client.
        DB::statement(<<<'SQL'
            ALTER TABLE products
              ADD CONSTRAINT products_default_discount_check
              CHECK (default_discount_percent >= 0 AND default_discount_percent <= 100)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT IF EXISTS products_default_discount_check');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('default_discount_percent');
        });
    }
};
