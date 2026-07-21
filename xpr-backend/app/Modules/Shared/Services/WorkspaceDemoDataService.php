<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Données de démonstration réalistes pour les écrans applicatifs (Phase 0).
 * Appelé à la création de la première société — pas de mock dans les contrôleurs.
 */
final class WorkspaceDemoDataService
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function seedForCompany(Company $company): void
    {
        if (Invoice::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }

        // Le provisioning tourne AVANT que le middleware n'ait activé une
        // société : on pose le contexte le temps du seed. Le trait
        // BelongsToCompany renseigne alors company_id lui-même — il n'est
        // volontairement pas `fillable`, un create() ne peut pas le porter.
        $this->tenant->runForCompany($company->id, function () use ($company): void {
            $this->seedRows($company);
        });
    }

    private function seedRows(Company $company): void
    {
        $today = Carbon::today();
        $currency = $company->default_currency;

        $invoices = [
            ['number' => 'FAC-2026-0001', 'client_name' => 'Atlas Distribution SARL', 'issued_at' => $today->copy()->subDays(25), 'due_at' => $today->copy()->subDays(5), 'status' => 'overdue', 'total_cents' => 4580000],
            ['number' => 'FAC-2026-0002', 'client_name' => 'Café Maure', 'issued_at' => $today->copy()->subDays(18), 'due_at' => $today->copy()->addDays(12), 'status' => 'sent', 'total_cents' => 1250000],
            ['number' => 'FAC-2026-0003', 'client_name' => 'TechMaroc SARL', 'issued_at' => $today->copy()->subDays(12), 'due_at' => $today->copy()->subDays(2), 'status' => 'partial', 'total_cents' => 8900000],
            ['number' => 'FAC-2026-0004', 'client_name' => 'Riad Azur', 'issued_at' => $today->copy()->subDays(8), 'due_at' => $today->copy()->addDays(22), 'status' => 'paid', 'total_cents' => 3200000],
            ['number' => null, 'client_name' => 'Studio Créatif Casablanca', 'issued_at' => null, 'due_at' => null, 'status' => 'draft', 'total_cents' => 1750000],
            ['number' => 'FAC-2026-0005', 'client_name' => 'Boulangerie Al Fath', 'issued_at' => $today->copy()->subDays(45), 'due_at' => $today->copy()->subDays(15), 'status' => 'paid', 'total_cents' => 980000],
            ['number' => 'FAC-2026-0006', 'client_name' => 'Société Immobilière Anfa', 'issued_at' => $today->copy()->subDays(60), 'due_at' => $today->copy()->subDays(30), 'status' => 'cancelled', 'total_cents' => 15000000],
        ];

        foreach ($invoices as $row) {
            Invoice::create([
                ...$row,
                'currency' => $currency,
            ]);
        }

        $movements = [
            ['occurred_at' => $today->copy()->subDays(2), 'label' => 'Encaissement facture FAC-2026-0004', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => 3200000],
            ['occurred_at' => $today->copy()->subDays(5), 'label' => 'Achat fournitures bureau', 'method' => 'cash', 'register_name' => 'Caisse principale', 'amount_cents' => -45000],
            ['occurred_at' => $today->copy()->subDays(8), 'label' => 'Acompte TechMaroc', 'method' => 'cheque', 'register_name' => 'Caisse principale', 'amount_cents' => 4000000],
            ['occurred_at' => $today->copy()->subDays(12), 'label' => 'Paiement loyer', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => -850000],
            ['occurred_at' => $today->copy()->subDays(15), 'label' => 'Encaissement Boulangerie Al Fath', 'method' => 'cash', 'register_name' => 'Petite caisse', 'amount_cents' => 980000],
        ];

        foreach ($movements as $row) {
            CashMovement::create([
                ...$row,
                'currency' => $currency,
            ]);
        }
    }
}
