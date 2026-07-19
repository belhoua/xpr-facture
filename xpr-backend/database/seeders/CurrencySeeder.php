<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel de devises minimal du marché cible : MAD (pivot) + les deux
 * devises d'import/export dominantes au Maroc. Idempotent (upsert).
 */
final class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('currencies')->upsert([
            ['code' => 'MAD', 'name_fr' => 'Dirham marocain', 'name_ar' => 'درهم مغربي', 'symbol' => 'DH', 'decimal_places' => 2],
            ['code' => 'EUR', 'name_fr' => 'Euro', 'name_ar' => 'يورو', 'symbol' => '€', 'decimal_places' => 2],
            ['code' => 'USD', 'name_fr' => 'Dollar américain', 'name_ar' => 'دولار أمريكي', 'symbol' => '$', 'decimal_places' => 2],
        ], ['code'], ['name_fr', 'name_ar', 'symbol', 'decimal_places']);
    }
}
