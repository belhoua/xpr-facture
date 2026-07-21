<?php

declare(strict_types=1);

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Accounting\Exceptions\NoFiscalYearForDate;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Sequence;
use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Accounting\Services\DocumentNumberService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation des documents (§3) : continue, sans trou, sans réutilisation,
 * remise à zéro à chaque exercice.
 */

/** Société initialisée (exercice courant + séquences) et contexte tenant armé. */
function numberingCompany(): Company
{
    $company = Company::factory()->create();
    app(CompanyAccountingProvisioning::class)->initialize($company);
    app(TenantContext::class)->activateCompany($company->id);

    return $company;
}

function allocate(DocumentType $type, Carbon $date): string
{
    return DB::transaction(fn (): string => app(DocumentNumberService::class)->allocate($type, $date));
}

it('numérote à partir de 1 et sans trou', function (): void {
    numberingCompany();
    $date = Carbon::today();

    $numbers = collect(range(1, 5))
        ->map(fn (): string => allocate(DocumentType::Invoice, $date))
        ->all();

    $year = $date->format('Y');

    expect($numbers)->toBe([
        "FAC-{$year}-0001",
        "FAC-{$year}-0002",
        "FAC-{$year}-0003",
        "FAC-{$year}-0004",
        "FAC-{$year}-0005",
    ]);
});

it('tient une séquence distincte par type de document', function (): void {
    numberingCompany();
    $date = Carbon::today();
    $year = $date->format('Y');

    allocate(DocumentType::Invoice, $date);

    expect(allocate(DocumentType::Quote, $date))->toBe("DEV-{$year}-0001")
        ->and(allocate(DocumentType::Invoice, $date))->toBe("FAC-{$year}-0002")
        ->and(allocate(DocumentType::CreditNote, $date))->toBe("AV-{$year}-0001");
});

it('repart à 0001 au changement d exercice', function (): void {
    $company = numberingCompany();
    $thisYear = Carbon::today();

    allocate(DocumentType::Invoice, $thisYear);
    allocate(DocumentType::Invoice, $thisYear);

    // Exercice suivant, ouvert par la société au 1er janvier
    $nextYear = $thisYear->copy()->addYear();
    FiscalYear::query()->create([
        'label' => $nextYear->format('Y'),
        'starts_on' => $nextYear->copy()->startOfYear(),
        'ends_on' => $nextYear->copy()->endOfYear(),
        'status' => FiscalYearStatus::Open,
    ]);

    $first = allocate(DocumentType::Invoice, $nextYear->copy()->startOfYear());

    expect($first)->toBe('FAC-'.$nextYear->format('Y').'-0001')
        // L'exercice précédent garde son compteur : le prochain document daté
        // de cette année-là suit sa propre séquence.
        ->and(allocate(DocumentType::Invoice, $thisYear))->toBe('FAC-'.$thisYear->format('Y').'-0003')
        ->and(Sequence::query()->where('company_id', $company->id)->count())->toBe(4);
});

it('ne laisse aucun trou quand la transaction échoue', function (): void {
    numberingCompany();
    $date = Carbon::today();
    $year = $date->format('Y');

    expect(allocate(DocumentType::Invoice, $date))->toBe("FAC-{$year}-0001");

    // Une validation qui échoue après l'attribution du numéro : le compteur
    // doit revenir en arrière avec la transaction, sinon 0002 serait perdu.
    try {
        DB::transaction(function (): void {
            app(DocumentNumberService::class)->allocate(DocumentType::Invoice, Carbon::today());

            throw new RuntimeException('échec de validation après numérotation');
        });
    } catch (RuntimeException) {
        // attendu
    }

    expect(allocate(DocumentType::Invoice, $date))->toBe("FAC-{$year}-0002");
});

// La garde « hors transaction » ne peut pas être vérifiée ici : RefreshDatabase
// ouvre une transaction autour de chaque test, transactionLevel() n'y vaut
// jamais 0. Elle est couverte par tests/Unit/Accounting/NumberingGuardTest.php.

it('refuse une date que ne couvre aucun exercice', function (): void {
    numberingCompany();

    allocate(DocumentType::Invoice, Carbon::today()->subYears(3));
})->throws(NoFiscalYearForDate::class);

it('formate le numéro avec le millésime de l exercice, pas celui du jour', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->activateCompany($company->id);

    // Exercice décalé : 1er juillet 2026 → 30 juin 2027
    FiscalYear::query()->create([
        'label' => '2026-2027',
        'starts_on' => Carbon::parse('2026-07-01'),
        'ends_on' => Carbon::parse('2027-06-30'),
        'status' => FiscalYearStatus::Open,
    ]);

    // Un document émis en mars 2027 appartient encore à l'exercice 2026 : son
    // numéro doit porter 2026, sinon la séquence paraîtrait trouée.
    expect(allocate(DocumentType::Invoice, Carbon::parse('2027-03-15')))->toBe('FAC-2026-0001')
        ->and(allocate(DocumentType::Invoice, Carbon::parse('2026-09-02')))->toBe('FAC-2026-0002');
});

it('respecte un format personnalisé par la société', function (): void {
    numberingCompany();
    $year = Carbon::today()->format('Y');

    Sequence::query()
        ->where('document_type', DocumentType::Invoice->value)
        ->update(['format' => 'F/{YY}/{000000}']);

    expect(allocate(DocumentType::Invoice, Carbon::today()))
        ->toBe('F/'.substr($year, -2).'/000001');
});

it('isole les séquences entre deux sociétés', function (): void {
    $companyA = numberingCompany();
    $year = Carbon::today()->format('Y');

    allocate(DocumentType::Invoice, Carbon::today());
    allocate(DocumentType::Invoice, Carbon::today());

    $companyB = numberingCompany();

    // B démarre sa propre séquence à 0001 : le compteur de A ne fuit pas.
    expect(allocate(DocumentType::Invoice, Carbon::today()))->toBe("FAC-{$year}-0001")
        ->and(Sequence::query()->where('company_id', $companyA->id)->exists())->toBeFalse()
        ->and(Sequence::query()->where('company_id', $companyB->id)->exists())->toBeTrue();
});

it('ouvre un exercice sur l année civile à la création d une société', function (): void {
    $company = Company::factory()->create();

    $fiscalYear = app(CompanyAccountingProvisioning::class)->initialize($company);
    app(TenantContext::class)->activateCompany($company->id);

    $today = Carbon::today();

    expect($fiscalYear->label)->toBe($today->format('Y'))
        ->and($fiscalYear->starts_on->toDateString())->toBe($today->copy()->startOfYear()->toDateString())
        ->and($fiscalYear->ends_on->toDateString())->toBe($today->copy()->endOfYear()->toDateString())
        ->and($fiscalYear->status)->toBe(FiscalYearStatus::Open)
        // Facture, devis et avoir sont provisionnés d'emblée
        ->and(Sequence::query()->count())->toBe(3);
});

it('interdit deux exercices qui se chevauchent', function (): void {
    numberingCompany();
    $today = Carbon::today();

    FiscalYear::query()->create([
        'label' => 'chevauchant',
        'starts_on' => $today->copy()->startOfYear()->addMonths(6),
        'ends_on' => $today->copy()->endOfYear()->addMonths(6),
        'status' => FiscalYearStatus::Open,
    ]);
})->throws(QueryException::class);
