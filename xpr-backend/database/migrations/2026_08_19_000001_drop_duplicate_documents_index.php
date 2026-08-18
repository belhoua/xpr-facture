<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Retire un index EXACTEMENT dupliqué sur `documents`.
 *
 * `invoices_company_id_partner_id_index` et
 * `documents_company_id_partner_id_index` portent les mêmes colonnes, dans le
 * même ordre, sur la même table :
 *
 *     btree (company_id, partner_id)
 *
 * ── Comment le doublon est né ─────────────────────────────────────────────
 *
 * Le renommage `invoices` → `documents` (migration 2026_07_21_000016) a
 * renommé la TABLE ; PostgreSQL conserve alors les noms d'index existants,
 * restés en `invoices_*`. La migration de performance du 2026-08-16 a ensuite
 * créé `documents_company_id_partner_id_index` avec un `IF NOT EXISTS` — qui
 * teste le NOM, jamais la définition. Le nom était libre : l'index a été créé
 * une seconde fois, à l'identique.
 *
 * ── Pourquoi le supprimer ─────────────────────────────────────────────────
 *
 * Un index en double n'accélère aucune lecture : le planificateur en choisit
 * un et ignore l'autre. Il coûte en revanche à CHAQUE écriture — insertion,
 * modification, annulation d'un document maintiennent deux arbres identiques —
 * et occupe deux fois la place en cache disque, au détriment des index qui
 * servent vraiment.
 *
 * C'est celui au nom hérité qui part : `documents_*` est cohérent avec la
 * table, et c'est le nom que la migration de performance documente.
 *
 * ── Ce qui RESTE, et pourquoi ─────────────────────────────────────────────
 *
 * `invoices_company_id_status_index` et `invoices_company_id_issued_at_index`
 * portent eux aussi un nom hérité, mais ne sont PAS des doublons : les index
 * `documents_*` correspondants incluent `type` en deuxième position, et un
 * index composite ne sert que ses préfixes. Une lecture filtrée sur le statut
 * ou la date d'émission SANS filtre de type — la liste tous types confondus —
 * ne serait couverte par rien s'ils disparaissaient. Seul leur nom est
 * trompeur ; les renommer relève du cosmétique, pas de la performance.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_company_id_partner_id_index');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS invoices_company_id_partner_id_index
              ON documents (company_id, partner_id)
        SQL);
    }
};
