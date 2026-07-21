<?php

declare(strict_types=1);

use App\Modules\Accounting\Enums\TaxKind;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Catalogue de TVA : lignes globales partagées + taux propres à la société
 * (décision du 2026-07-21). Ce fichier vérifie que le partage ne crée aucune
 * fuite entre sociétés — c'est le risque du modèle à company_id nullable.
 */

/** Nombre de taux du catalogue standard marocain (TaxRateSeeder). */
const STANDARD_RATES = 7;

function activate(Company $company): Company
{
    app(TenantContext::class)->activateCompany($company->id);

    return $company;
}

it('expose le catalogue standard à toute société', function (): void {
    activate(Company::factory()->create());

    expect(TaxRate::query()->count())->toBe(STANDARD_RATES)
        ->and(TaxRate::query()->where('label_fr', 'TVA 20 %')->exists())->toBeTrue();
});

it('marque les taux du catalogue comme globaux', function (): void {
    activate(Company::factory()->create());

    $standard = TaxRate::query()->where('label_fr', 'TVA 20 %')->firstOrFail();

    expect($standard->isGlobal())->toBeTrue()
        ->and($standard->company_id)->toBeNull()
        ->and($standard->is_default)->toBeTrue()
        ->and($standard->kind)->toBe(TaxKind::Standard);
});

it('rattache automatiquement un taux créé à la société active', function (): void {
    $company = activate(Company::factory()->create());

    $custom = TaxRate::query()->create([
        'label_fr' => 'TVA 20 % — régime spécifique',
        'label_ar' => 'ضريبة القيمة المضافة 20 ٪ — نظام خاص',
        'rate' => '20.00',
        'kind' => TaxKind::Standard,
    ]);

    expect($custom->company_id)->toBe($company->id)
        ->and($custom->isGlobal())->toBeFalse()
        ->and(TaxRate::query()->count())->toBe(STANDARD_RATES + 1);
});

it('ne montre pas à une société les taux d une autre', function (): void {
    $companyA = activate(Company::factory()->create());

    TaxRate::query()->create([
        'label_fr' => 'Taux interne A',
        'label_ar' => 'نسبة داخلية أ',
        'rate' => '12.50',
        'kind' => TaxKind::Standard,
    ]);

    activate(Company::factory()->create());

    // B voit le catalogue standard, et rien de A
    expect(TaxRate::query()->count())->toBe(STANDARD_RATES)
        ->and(TaxRate::query()->where('label_fr', 'Taux interne A')->exists())->toBeFalse()
        // Même en visant explicitement le company_id de A : le scope parenthésé
        // de BelongsToCompanyOrGlobal ne laisse pas un ->where() de l'appelant
        // basculer en OR avec les lignes globales.
        ->and(TaxRate::query()->where('company_id', $companyA->id)->count())->toBe(0);

    // LIMITE CONNUE : ce test prouve le scope Eloquent, pas la RLS. La suite
    // tourne avec xpr_owner (phpunit.xml), qui est SUPERUSER et donc BYPASSRLS —
    // FORCE ROW LEVEL SECURITY ne s'applique pas à lui. Une requête brute
    // DB::table('tax_rates') voit ici les lignes de A. Prouver la RLS exige un
    // rôle de test non-superuser propriétaire de la base (cf. P0-09).
});

it('combine le catalogue et les taux propres sans les mélanger', function (): void {
    $company = activate(Company::factory()->create());

    TaxRate::query()->create([
        'label_fr' => 'Taux négocié',
        'label_ar' => 'نسبة متفاوض عليها',
        'rate' => '5.00',
        'kind' => TaxKind::Standard,
    ]);

    $own = TaxRate::query()->whereNotNull('company_id')->get();
    $catalog = TaxRate::query()->whereNull('company_id')->get();

    expect($own)->toHaveCount(1)
        ->and($own->first()?->company_id)->toBe($company->id)
        ->and($catalog)->toHaveCount(STANDARD_RATES);
});

it('convertit le taux en centièmes de point sans passer par un flottant', function (): void {
    activate(Company::factory()->create());

    $rates = TaxRate::query()->whereNull('company_id')->get()
        ->mapWithKeys(fn (TaxRate $rate): array => [$rate->label_fr => $rate->rateBasisPoints()]);

    expect($rates['TVA 20 %'])->toBe(2000)
        ->and($rates['TVA 14 %'])->toBe(1400)
        ->and($rates['TVA 7 %'])->toBe(700)
        ->and($rates['Exonéré de TVA'])->toBe(0);
});

it('interdit deux taux par défaut dans le même périmètre', function (): void {
    activate(Company::factory()->create());

    // Le catalogue global a déjà TVA 20 % par défaut ; une seconde ligne
    // globale par défaut doit être refusée par l'index partiel unique.
    DB::table('tax_rates')->insert([
        'company_id' => null,
        'label_fr' => 'Doublon par défaut',
        'label_ar' => 'مكرر',
        'rate' => '20.00',
        'kind' => TaxKind::Standard->value,
        'is_default' => true,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(UniqueConstraintViolationException::class);
