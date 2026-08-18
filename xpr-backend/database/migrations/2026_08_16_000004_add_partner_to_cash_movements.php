<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattache un mouvement de caisse au TIERS concerné.
 *
 * ── Pourquoi la colonne n'existait pas ────────────────────────────────────
 *
 * Le journal de caisse ne portait que `label`, un texte libre : « Encaissement
 * Riad Azur ». Le nom du client y était donc écrit à la main, non recherchable,
 * non regroupable, et faux dès qu'on renomme le tiers. L'écran Caisses ne
 * pouvait pas afficher de colonne Client parce qu'aucune donnée ne la portait.
 *
 * ── NULLABLE, et ce n'est pas un compromis ────────────────────────────────
 *
 * Un décaissement n'a souvent aucun tiers de ce répertoire — un loyer, un plein
 * de carburant, un achat de fournitures. Rendre la colonne obligatoire aurait
 * forcé à inventer un tiers pour chaque dépense. Les mouvements déjà en base
 * restent donc à NULL : l'écran affiche « — » plutôt qu'un nom deviné.
 *
 * `nullOnDelete` : archiver un tiers ne doit pas effacer une écriture de
 * caisse, qui est un fait comptable. Le mouvement survit, orphelin de son
 * libellé de tiers — d'où l'affichage prévu pour ce cas côté écran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->foreignUuid('partner_id')->nullable()->after('company_id')
                ->constrained('partners')->nullOnDelete();

            // L'écran Caisses filtre par tiers ; `company_id` en tête comme
            // toute lecture sous le scope tenant (§7).
            $table->index(['company_id', 'partner_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'partner_id']);
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
