<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Classe les DÉCAISSEMENTS par nature de charge.
 *
 * « Loyer », « Fournitures de bureau », « Frais de déplacement » : le journal
 * disait jusqu'ici COMBIEN était sorti et par quel moyen, jamais POUR QUOI. Le
 * libellé porte le détail de l'écriture (« Ramettes A4 + toner »), la charge en
 * porte la nature — c'est elle qui permettra de regrouper les sorties.
 *
 * ── Une COLONNE et non une table de référentiel ───────────────────────────
 *
 * Un texte libre, alimenté par un champ qui propose les valeurs déjà saisies
 * dans la société (`GET /cash/charges`). La liste se construit donc à l'usage,
 * sans écran de gestion à livrer ni référentiel à provisionner à l'inscription.
 *
 * `categories` n'est pas réutilisée, bien qu'elle existe : elle classe ce que
 * la société VEND (« Prestation », « Conseil »). Y verser des natures de
 * dépense mêlerait deux référentiels de sens opposés dans le même déroulant.
 *
 * Ce que ce choix coûte, et qui viendra : pas de contrainte d'orthographe —
 * « Loyer » et « loyers » cohabiteront —, pas de couleur, pas de budget par
 * poste. Le jour où l'exploitant voudra un référentiel fermé et des états par
 * nature de charge, une table reprendra les valeurs distinctes de cette
 * colonne : elle est le brouillon de ce référentiel, pas son remplacement.
 *
 * NULLABLE, et pas seulement pour les lignes déjà en base : la charge reste
 * FACULTATIVE. L'exiger obligerait à inventer une nature pour chaque sortie
 * pressée, et une nature inventée vaut moins qu'une case vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->string('charge', 120)->nullable()->after('label');

            // Sert la liste des charges déjà employées, qui alimente le champ
            // de saisie : `SELECT DISTINCT charge ... ORDER BY charge` sous le
            // scope de la société.
            $table->index(['company_id', 'charge']);
        });
    }

    public function down(): void
    {
        Schema::table('cash_movements', function (Blueprint $table): void {
            $table->dropIndex(['company_id', 'charge']);
            $table->dropColumn('charge');
        });
    }
};
