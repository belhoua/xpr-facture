<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ajoute le type de tiers « intermédiaire ».
 *
 * ── Deux changements, et le premier n'est pas cosmétique ──────────────────
 *
 * `partners.type` est un `VARCHAR(10)`, dimensionné pour « supplier » (8). La
 * valeur `intermediary` en fait 12 : sans l'élargissement, PostgreSQL refuse
 * l'insertion avec une erreur de troncature, et le CHECK élargi ne servirait à
 * rien. La colonne passe donc à 20 — de la marge pour un type à venir, sans
 * ouvrir un champ libre là où quatre valeurs sont attendues.
 *
 * Le CHECK est REMPLACÉ et non simplement recréé : PostgreSQL n'a pas d'ALTER
 * CONSTRAINT pour une contrainte de vérification. La suppression puis la
 * recréation se font dans la même migration, donc dans la même transaction —
 * la table n'est jamais sans garde.
 *
 * ── Ce que ce type NE fait PAS (décision du 2026-08-17) ───────────────────
 *
 * Un intermédiaire est un type AUTONOME : il ne rejoint ni la liste des
 * clients, ni celle des fournisseurs. `PartnerType::isClient()` et
 * `isSupplier()` le laissent donc à `false`, et aucun déroulant de facturation
 * ne le propose. Le type sert à CLASSER le tiers dans le répertoire, pas à lui
 * ouvrir un cycle commercial — l'ouvrir plus tard reste possible, le refermer
 * après coup laisserait des documents rattachés derrière lui.
 *
 * Aucune donnée existante n'est touchée : `client`, `supplier` et `both`
 * restent valides et gardent leur sens.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `USING` inutile ici : VARCHAR(10) → VARCHAR(20) est un élargissement,
        // PostgreSQL n'a aucune conversion à faire et ne réécrit pas la table.
        DB::statement('ALTER TABLE partners ALTER COLUMN type TYPE VARCHAR(20)');

        DB::statement('ALTER TABLE partners DROP CONSTRAINT partners_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE partners
              ADD CONSTRAINT partners_type_check
              CHECK (type IN ('client','supplier','both','intermediary'))
        SQL);
    }

    public function down(): void
    {
        // Les fiches déjà classées « intermédiaire » redeviennent des clients :
        // les laisser bloquerait la recréation du CHECK, et les supprimer
        // effacerait des tiers que rien ne remplace. `client` est le défaut de
        // la colonne, donc le repli le moins surprenant.
        DB::statement("UPDATE partners SET type = 'client' WHERE type = 'intermediary'");

        DB::statement('ALTER TABLE partners DROP CONSTRAINT partners_type_check');

        DB::statement(<<<'SQL'
            ALTER TABLE partners
              ADD CONSTRAINT partners_type_check
              CHECK (type IN ('client','supplier','both'))
        SQL);

        DB::statement('ALTER TABLE partners ALTER COLUMN type TYPE VARCHAR(10)');
    }
};
