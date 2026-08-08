<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;

/**
 * Nomenclature de départ du catalogue : les natures de prestation courantes.
 *
 * Elles sont créées en BASE, comme des catégories ordinaires, et non figées
 * dans une énumération PHP. C'est la seule forme qui tienne : la nomenclature
 * d'un cabinet comptable n'est pas celle d'une agence web, et §3 impose que
 * tout référentiel métier soit paramétrable sans migration. La société les
 * renomme, les archive ou en ajoute depuis l'écran Services.
 *
 * Appelé par CompanyProvisioning, dans la transaction d'inscription.
 */
final class CompanyCatalogProvisioning
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Natures de service proposées par défaut. La couleur sert au repérage
     * visuel dans les listes denses (§11) ; les teintes sont distinctes deux à
     * deux pour rester lisibles côte à côte.
     *
     * @var list<array{name: string, color: string}>
     */
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Prestation', 'color' => '#2563EB'],
        ['name' => 'Conseil', 'color' => '#7C3AED'],
        ['name' => 'Maintenance', 'color' => '#059669'],
        ['name' => 'Forfait', 'color' => '#D97706'],
    ];

    public function initialize(Company $company): void
    {
        // Même raison que dans CompanyAccountingProvisioning : l'inscription
        // écrit avant qu'un middleware n'ait activé de société, et `categories`
        // est sous RLS.
        $this->tenant->runForCompany($company->id, function (): void {
            foreach (self::DEFAULT_CATEGORIES as $row) {
                // firstOrCreate et non create : l'index unique partiel
                // `categories_company_name_unique` porte sur lower(name), et le
                // jeu de démonstration s'appuie sur ces mêmes libellés.
                Category::query()->firstOrCreate(
                    ['name' => $row['name']],
                    ['color' => $row['color']],
                );
            }
        });
    }
}
