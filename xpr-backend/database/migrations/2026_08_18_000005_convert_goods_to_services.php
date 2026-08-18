<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convertit les BIENS restants du catalogue en SERVICES.
 *
 * ── Pourquoi ────────────────────────────────────────────────────────────
 *
 * L'exploitant ne commercialise aucun bien physique (décision du 2026-08-18).
 * Depuis que l'écran Catalogue ne présente plus que des catégories et que
 * `/services` filtre sur le seul type `service`, un article de type `good`
 * n'est visible NULLE PART : il reste facturable depuis une ligne de document,
 * mais plus personne ne peut le corriger ni l'archiver. Le convertir est la
 * seule façon de le rendre à nouveau atteignable.
 *
 * ── `track_stock` doit tomber en même temps ─────────────────────────────
 *
 * `products_stock_goods_only_check` interdit `track_stock` sur un service. La
 * mise à jour doit donc porter sur les deux colonnes dans le MÊME ordre SQL :
 * convertir d'abord et décocher ensuite ferait échouer la première requête sur
 * tout article suivi en stock.
 *
 * ── Ce que cette migration NE fait PAS ──────────────────────────────────
 *
 * Elle ne touche ni aux prix, ni aux unités, ni aux références : un bien
 * converti garde son libellé et son tarif, et reste la même ligne dans les
 * factures qui le citent déjà. Elle ne restreint pas non plus le CHECK
 * `products_type_check`, qui accepte toujours `good` — fermer la porte est une
 * décision distincte de vider la pièce, et elle n'a pas été demandée.
 *
 * Idempotente : rejouée, elle ne trouve plus rien à convertir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $converted = DB::table('products')
            ->where('type', 'good')
            ->update(['type' => 'service', 'track_stock' => false]);

        // Les archivés sont convertis eux aussi — `update()` ignore le soft
        // delete, et c'est voulu : un bien archivé restauré plus tard doit
        // revenir en service, pas ressusciter un type que le produit n'expose
        // plus.
        if ($converted > 0) {
            info("convert_goods_to_services: {$converted} article(s) converti(s).");
        }
    }

    /**
     * Vide, et ce n'est pas un oubli : rien n'enregistre QUELS articles étaient
     * des biens avant la conversion. Un `down()` qui les rebasculerait devrait
     * deviner lesquels — et transformerait en matériel des services que
     * l'utilisateur aura créés depuis.
     */
    public function down(): void {}
};
