<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prépare `cash_movements` à porter une COPIE de chaque règlement de facture.
 *
 * ── Le changement de modèle, et ce qu'il coûte ────────────────────────────
 *
 * Jusqu'ici, le journal de caisse LISAIT les règlements : `CashSummaryService`
 * fusionnait `cash_movements` et `payments` à l'affichage, sans rien dupliquer.
 * Sur demande expresse de l'exploitant (2026-08-25), chaque règlement écrit
 * désormais son propre mouvement de caisse.
 *
 * Ce que la duplication coûte, dit ici pour que personne n'ait à le
 * redécouvrir : deux copies d'un même fait peuvent DIVERGER. La fusion en
 * lecture ne pouvait pas se tromper — elle relisait la source. Une copie, si :
 * il suffit qu'une écriture passe à côté du miroir (import, reprise de
 * données, requête SQL directe) pour que la caisse et les factures cessent de
 * dire la même chose, sans que rien ne le signale.
 *
 * Trois garde-fous limitent la dérive, et il faut les tenir ensemble :
 *  1. `payment_id` rattache le mouvement à son règlement — sans lui, on ne
 *     saurait pas quelle ligne mettre à jour ni supprimer ;
 *  2. l'index UNIQUE partiel interdit deux miroirs pour un même règlement, y
 *     compris si la synchronisation est rejouée ;
 *  3. le mouvement dérivé est en LECTURE SEULE dans l'application (cf.
 *     `CashMovementWriteService`) : le corriger passe par la facture.
 *
 * ── Trois ajustements de colonnes, tous imposés par la copie ──────────────
 *
 * Ils ne sont pas cosmétiques : sans eux, la création du miroir échouerait en
 * base, donc l'enregistrement du règlement lui-même.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            // 1. LE RATTACHEMENT au règlement d'origine.
            //
            // `nullOnDelete` et non `cascadeOnDelete` : `payments` est en soft
            // delete, la cascade SQL ne se déclencherait donc jamais dans le
            // cours normal des choses — c'est le service qui retire le miroir.
            // La règle ne joue qu'à l'effacement DUR d'un règlement, où elle
            // laisse un mouvement orphelin plutôt que d'emporter une écriture
            // de trésorerie sans le dire.
            $table->foreignUuid('payment_id')->nullable()->after('partner_id')
                ->constrained('payments')->nullOnDelete();
        });

        // 2. UN SEUL miroir par règlement.
        //
        // Index partiel plutôt que contrainte UNIQUE : les mouvements saisis à
        // la main portent tous `payment_id NULL`, et un UNIQUE ordinaire les
        // accepterait sans compter (NULL n'égale jamais NULL en SQL) — mais
        // l'index partiel dit l'intention et reste plus compact.
        DB::statement(
            'CREATE UNIQUE INDEX cash_movements_payment_unique
             ON cash_movements (payment_id) WHERE payment_id IS NOT NULL'
        );

        // 3. La CAISSE PHYSIQUE devient facultative.
        //
        // Un règlement reçu par virement ou par chèque n'entre dans aucune
        // caisse. La colonne était NOT NULL parce que toute écriture était
        // saisie au comptoir ; il faudrait désormais inventer un nom de caisse
        // pour chaque virement, et cette invention deviendrait une donnée.
        DB::statement('ALTER TABLE cash_movements ALTER COLUMN register_name DROP NOT NULL');

        // 4. Les MODES de règlement bancaires rejoignent la liste autorisée.
        //
        // `payments_method_check` accepte `lcn` et `deposit` ;
        // `cash_movements_method_check` ne les connaissait pas. Sans cet
        // élargissement, encaisser une facture par LCN ferait échouer la copie
        // — et donc, la copie étant dans la transaction du règlement,
        // l'enregistrement du règlement lui-même.
        //
        // Ces deux modes restent hors du formulaire de saisie manuelle : ils
        // n'ont de sens que sur un règlement de facture, et
        // `cashMovementFormSchema` ne les propose pas.
        DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT IF EXISTS cash_movements_method_check');
        DB::statement(
            "ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_method_check
             CHECK (method IN ('cash', 'cheque', 'transfer', 'card', 'effect', 'lcn', 'deposit'))"
        );
    }

    public function down(): void
    {
        // Les miroirs partent AVANT le rétrécissement des contraintes : une
        // ligne `lcn` ou sans caisse ferait échouer les ALTER qui suivent.
        DB::table('cash_movements')->whereNotNull('payment_id')->delete();

        DB::statement('DROP INDEX IF EXISTS cash_movements_payment_unique');

        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_id');
        });

        DB::statement('ALTER TABLE cash_movements DROP CONSTRAINT IF EXISTS cash_movements_method_check');
        DB::statement(
            "ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_method_check
             CHECK (method IN ('cash', 'cheque', 'transfer', 'card', 'effect'))"
        );

        DB::statement("UPDATE cash_movements SET register_name = '—' WHERE register_name IS NULL");
        DB::statement('ALTER TABLE cash_movements ALTER COLUMN register_name SET NOT NULL');
    }
};
