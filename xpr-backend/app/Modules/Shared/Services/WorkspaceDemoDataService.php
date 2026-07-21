<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Services\DocumentNumberService;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration réalistes pour les écrans applicatifs (Phase 0).
 * Appelé à la création de la première société — pas de mock dans les contrôleurs.
 *
 * Prérequis : la société doit avoir son exercice et ses séquences
 * (CompanyAccountingProvisioning), puisque les factures semées sont numérotées
 * comme de vraies factures validées.
 */
final class WorkspaceDemoDataService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentNumberService $numbers,
    ) {}

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
            // Transaction explicite : la numérotation exige un verrou de ligne,
            // et ce service est aussi appelé hors du provisioning (fixtures de
            // test). Imbriquée, elle devient un savepoint — sans effet de bord.
            DB::transaction(fn () => $this->seedRows($company));
        });
    }

    /**
     * Date d'émission bornée à l'exercice courant.
     *
     * Le provisioning n'ouvre que l'exercice de l'année civile en cours : une
     * société créée le 10 janvier n'a pas d'exercice N-1, et une date « il y a
     * 60 jours » y tomberait — la numérotation échouerait alors sur
     * NoFiscalYearForDate. On rapproche la date plutôt que d'inventer un
     * exercice antérieur qui n'a pas lieu d'être.
     */
    private function daysAgo(int $days): Carbon
    {
        $today = Carbon::today();

        return $today->copy()->subDays($days)->max($today->copy()->startOfYear());
    }

    private function seedRows(Company $company): void
    {
        $today = Carbon::today();
        $currency = $company->default_currency;

        // Sans numéro en dur : ils sont alloués par la séquence de l'exercice,
        // comme le ferait une vraie validation. Un millésime figé aurait été
        // faux dès le changement d'année, et surtout la séquence serait repartie
        // à 0001 en écrasant ces numéros-là.
        $invoices = [
            ['client_name' => 'Société Immobilière Anfa', 'issued_at' => $this->daysAgo(60), 'due_at' => $today->copy()->subDays(30), 'status' => 'cancelled', 'total_cents' => 15000000],
            ['client_name' => 'Boulangerie Al Fath', 'issued_at' => $this->daysAgo(45), 'due_at' => $today->copy()->subDays(15), 'status' => 'paid', 'total_cents' => 980000],
            ['client_name' => 'Atlas Distribution S.A.R.L.', 'issued_at' => $this->daysAgo(25), 'due_at' => $today->copy()->subDays(5), 'status' => 'overdue', 'total_cents' => 4580000],
            ['client_name' => 'Café Maure', 'issued_at' => $this->daysAgo(18), 'due_at' => $today->copy()->addDays(12), 'status' => 'sent', 'total_cents' => 1250000],
            ['client_name' => 'TechMaroc Solutions', 'issued_at' => $this->daysAgo(12), 'due_at' => $today->copy()->subDays(2), 'status' => 'partial', 'total_cents' => 8900000],
            ['client_name' => 'Riad Azur', 'issued_at' => $this->daysAgo(8), 'due_at' => $today->copy()->addDays(22), 'status' => 'paid', 'total_cents' => 3200000],
        ];

        // Répertoire des tiers, créé AVANT les factures : chacune s'y rattache
        // par `partner_id`, et fige la raison sociale correspondante.
        $partners = [
            ['type' => 'client', 'legal_name' => 'Société Immobilière Anfa', 'city' => 'Casablanca', 'terms' => 45],
            ['type' => 'client', 'legal_name' => 'Boulangerie Al Fath', 'city' => 'Fès', 'terms' => 0],
            ['type' => 'client', 'legal_name' => 'Atlas Distribution S.A.R.L.', 'city' => 'Casablanca', 'terms' => 30],
            ['type' => 'client', 'legal_name' => 'Café Maure', 'city' => 'Rabat', 'terms' => 15],
            ['type' => 'client', 'legal_name' => 'TechMaroc Solutions', 'city' => 'Rabat', 'terms' => 30],
            ['type' => 'client', 'legal_name' => 'Riad Azur', 'city' => 'Marrakech', 'terms' => 30],
            ['type' => 'supplier', 'legal_name' => 'Imprimerie Rapide Fès', 'city' => 'Fès', 'terms' => 30],
            ['type' => 'supplier', 'legal_name' => 'Fournitures Bureau Maroc', 'city' => 'Casablanca', 'terms' => 15],
            // Cas fréquent : à la fois client et fournisseur.
            ['type' => 'both', 'legal_name' => 'Consulting RH Maghreb', 'city' => 'Casablanca', 'terms' => 60],
        ];

        $partnersByName = [];

        foreach ($partners as $index => $row) {
            $partnersByName[$row['legal_name']] = Partner::create([
                'type' => $row['type'],
                'legal_name' => $row['legal_name'],
                'city' => $row['city'],
                'rc_city' => $row['city'],
                // ICE fictif mais valide (15 chiffres) et unique par société.
                'ice' => str_pad((string) (100000000000 + $index), 15, '0', STR_PAD_LEFT),
                'payment_terms_days' => $row['terms'],
                'currency' => $currency,
            ]);
        }

        // Émises dans l'ordre chronologique : un numéro plus élevé correspond à
        // une émission plus tardive, ce qu'un contrôle fiscal attend.
        foreach ($invoices as $row) {
            Invoice::create([
                ...$row,
                // Le nom DOIT exister dans le répertoire construit juste au
                // dessus : une divergence est un bug du jeu de données, pas un
                // cas métier — on préfère l'exception au rattachement muet.
                'partner_id' => $partnersByName[$row['client_name']]->id,
                'number' => $this->numbers->allocate(DocumentType::Invoice, $row['issued_at']),
                'currency' => $currency,
            ]);
        }

        // Un brouillon n'a pas de numéro tant qu'il n'est pas validé (§3).
        Invoice::create([
            'client_name' => 'Studio Créatif Casablanca',
            'number' => null,
            'issued_at' => null,
            'due_at' => null,
            'status' => 'draft',
            'total_cents' => 1750000,
            'currency' => $currency,
        ]);

        $movements = [
            ['occurred_at' => $today->copy()->subDays(2), 'label' => 'Encaissement Riad Azur', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => 3200000],
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
