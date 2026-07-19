<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensions PostgreSQL du socle + fonction uuid_generate_v7().
 * PG16 n'a pas d'UUID v7 natif (PG18 seulement) : timestamp ms + bits
 * aléatoires → PK ordonnées dans le temps, index denses, zéro fuite métier.
 * Côté application, HasVersion7Uuids génère la même forme ; le DEFAULT SQL
 * couvre les insertions hors Eloquent (seeds SQL, scripts).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS citext;
            CREATE EXTENSION IF NOT EXISTS unaccent;
            CREATE EXTENSION IF NOT EXISTS btree_gist;

            CREATE OR REPLACE FUNCTION uuid_generate_v7() RETURNS uuid
            LANGUAGE sql VOLATILE PARALLEL SAFE AS $$
              SELECT encode(
                set_bit(
                  set_bit(
                    overlay(uuid_send(gen_random_uuid())
                            PLACING substring(int8send((extract(epoch FROM clock_timestamp()) * 1000)::bigint) FROM 3)
                            FROM 1 FOR 6),
                    52, 1),
                  53, 1),
                'hex')::uuid;
            $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS uuid_generate_v7()');
    }
};
