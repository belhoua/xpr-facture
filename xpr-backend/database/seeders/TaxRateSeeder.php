<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Enums\TaxKind;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue STANDARD de TVA marocaine : lignes à company_id NULL, partagées par
 * toutes les sociétés (décision du 2026-07-21). Chaque société peut y ajouter
 * ses propres taux ; elle ne modifie jamais ceux-ci.
 *
 * On écrit en query builder et non via le modèle : le trait
 * BelongsToCompanyOrGlobal remplirait company_id depuis le contexte tenant,
 * ce qui est exactement l'inverse de ce qu'on veut ici.
 *
 * Idempotent : rejouable sans dupliquer (`db:seed` hors migrate:fresh).
 */
final class TaxRateSeeder extends Seeder
{
    /**
     * Taux en vigueur au Maroc (CGI). Ils sont PARAMÉTRABLES, jamais codés en
     * dur dans la logique de calcul : cette liste n'est qu'une valeur initiale,
     * la réglementation évolue (§3).
     *
     * @var list<array{rate: string, label_fr: string, label_ar: string, kind: TaxKind, is_default: bool}>
     */
    private const RATES = [
        ['rate' => '20.00', 'label_fr' => 'TVA 20 %', 'label_ar' => 'ضريبة القيمة المضافة 20 ٪', 'kind' => TaxKind::Standard, 'is_default' => true],
        ['rate' => '14.00', 'label_fr' => 'TVA 14 %', 'label_ar' => 'ضريبة القيمة المضافة 14 ٪', 'kind' => TaxKind::Standard, 'is_default' => false],
        ['rate' => '10.00', 'label_fr' => 'TVA 10 %', 'label_ar' => 'ضريبة القيمة المضافة 10 ٪', 'kind' => TaxKind::Standard, 'is_default' => false],
        ['rate' => '7.00', 'label_fr' => 'TVA 7 %', 'label_ar' => 'ضريبة القيمة المضافة 7 ٪', 'kind' => TaxKind::Standard, 'is_default' => false],
        ['rate' => '0.00', 'label_fr' => 'TVA 0 %', 'label_ar' => 'ضريبة القيمة المضافة 0 ٪', 'kind' => TaxKind::Standard, 'is_default' => false],
        ['rate' => '0.00', 'label_fr' => 'Exonéré de TVA', 'label_ar' => 'معفى من الضريبة', 'kind' => TaxKind::Exonere, 'is_default' => false],
        ['rate' => '0.00', 'label_fr' => 'Hors champ de TVA', 'label_ar' => 'خارج نطاق الضريبة', 'kind' => TaxKind::HorsChamp, 'is_default' => false],
    ];

    public function run(): void
    {
        foreach (self::RATES as $rate) {
            $exists = DB::table('tax_rates')
                ->whereNull('company_id')
                ->where('label_fr', $rate['label_fr'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('tax_rates')->insert([
                'company_id' => null,
                'label_fr' => $rate['label_fr'],
                'label_ar' => $rate['label_ar'],
                'rate' => $rate['rate'],
                'kind' => $rate['kind']->value,
                'is_default' => $rate['is_default'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
