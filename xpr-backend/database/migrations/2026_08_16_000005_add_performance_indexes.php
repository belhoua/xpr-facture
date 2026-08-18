<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index de performance — ceux qui MANQUAIENT réellement.
 *
 * Les tables portent déjà l'essentiel de leur indexation, posée avec chaque
 * migration de création : `(company_id, type, status)` et
 * `(company_id, type, issued_at)` sur `documents`,
 * `(company_id, invoice_id)` et `(company_id, paid_on)` sur `payments`, les
 * trois index de `projects`. Cette migration n'ajoute donc que ce que ces
 * index ne couvrent pas — un index redondant n'accélère aucune lecture et
 * ralentit toutes les écritures.
 *
 * ── Ce qui n'est PAS créé ici, et pourquoi ────────────────────────────────
 *
 * Un index `(type, status)` SANS `company_id` en tête n'aurait jamais été
 * choisi par le planificateur : toute lecture passe par le global scope
 * tenant, `company_id` figure donc dans chaque WHERE, et
 * `(company_id, type, status)` — qui existe — le domine en toutes
 * circonstances. Il aurait coûté de l'écriture pour ne servir personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. La sous-requête corrélée des règlements ────────────────────
        //
        // `DocumentService::SETTLED_CENTS_SQL` corrèle sur `payments.invoice_id`
        // SEUL, sans `company_id` : elle est bornée par le document englobant,
        // déjà scopé. L'index `(company_id, invoice_id)` ne peut donc pas la
        // servir — `company_id` est en tête, et un index composite n'est
        // utilisable que par ses préfixes.
        //
        // Sans celui-ci, l'écran « situations par client » fait un parcours
        // séquentiel de `payments` PAR LIGNE de document. Vérifié :
        // `EXPLAIN` donnait un Seq Scan.
        //
        // PARTIEL sur `deleted_at IS NULL` : la sous-requête écarte les
        // règlements retirés, et les exclure de l'index le garde petit tout en
        // le rendant exactement assorti à la clause.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS payments_invoice_id_live_index
              ON payments (invoice_id)
              WHERE deleted_at IS NULL
        SQL);

        // ── 2. Documents d'un client, tous types confondus ────────────────
        //
        // `documents_company_id_type_partner_id_index` exige le TYPE : il sert
        // « les factures de ce client », pas « tout ce qui concerne ce
        // client », qui est précisément la lecture de l'écran par client
        // depuis qu'il agrège situations ET factures. Vérifié : `EXPLAIN`
        // donnait un Seq Scan.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS documents_company_id_partner_id_index
              ON documents (company_id, partner_id)
        SQL);

        // ── 3. Tri de repli de la liste des documents ─────────────────────
        //
        // `DocumentService::paginate()` trie `issued_at DESC, created_at DESC`.
        // Le tri est couvert par `(company_id, type, issued_at)` quand un type
        // est filtré ; sans filtre de type, il ne l'est par rien. Cet index
        // rattrape ce cas — et sert aussi les brouillons, qui n'ont pas de
        // date d'émission et se rangent sur `created_at`.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS documents_company_id_created_at_index
              ON documents (company_id, created_at DESC)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_invoice_id_live_index');
        DB::statement('DROP INDEX IF EXISTS documents_company_id_partner_id_index');
        DB::statement('DROP INDEX IF EXISTS documents_company_id_created_at_index');
    }
};
