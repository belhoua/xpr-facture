<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\Sequence;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Initialisation comptable d'une société : son premier exercice et ses
 * séquences de numérotation.
 *
 * Sans ces deux objets, aucune facture ne peut être validée — c'est donc un
 * passage obligé de la création de société, appelé par CompanyProvisioning.
 */
final class CompanyAccountingProvisioning
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function initialize(Company $company): FiscalYear
    {
        // Le provisioning a lieu avant que le middleware n'ait activé une
        // société : sans ce contexte, la RLS refuse l'INSERT et le trait
        // BelongsToCompany lève TenantContextMissing.
        return $this->tenant->runForCompany($company->id, function (): FiscalYear {
            $fiscalYear = $this->openCurrentFiscalYear();

            foreach (DocumentType::provisionedAtSignup() as $type) {
                Sequence::query()->create([
                    'fiscal_year_id' => $fiscalYear->id,
                    'document_type' => $type->value,
                    'format' => $type->defaultFormat(),
                    'next_number' => 1,
                ]);
            }

            return $fiscalYear;
        });
    }

    /**
     * Exercice sur l'ANNÉE CIVILE, décision produit du 2026-07-21 : c'est le cas
     * de l'écrasante majorité des TPE/PME marocaines. Une société à exercice
     * décalé modifie ensuite ses bornes — le schéma n'impose rien, seule cette
     * valeur par défaut le fait.
     */
    private function openCurrentFiscalYear(): FiscalYear
    {
        $today = Carbon::today();

        return FiscalYear::query()->create([
            'label' => $today->format('Y'),
            'starts_on' => $today->copy()->startOfYear(),
            'ends_on' => $today->copy()->endOfYear(),
            'status' => FiscalYearStatus::Open,
        ]);
    }
}
