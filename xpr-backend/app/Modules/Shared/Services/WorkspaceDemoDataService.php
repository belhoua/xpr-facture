<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Cash\Models\CashMovement;
use App\Modules\Catalog\Enums\ProductType;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Services\DocumentWriteService;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Données de démonstration réalistes pour les écrans applicatifs.
 * Appelé à la création de la première société — pas de mock dans les contrôleurs.
 *
 * Le jeu passe désormais par DocumentWriteService, exactement comme le ferait
 * l'interface : lignes réelles, TVA calculée par ligne, totaux dérivés,
 * numérotation consommée dans l'ordre chronologique. C'est le seul moyen d'être
 * sûr que ce que la démo affiche est ce que le moteur produit — un jeu de
 * données écrit « à la main » finit toujours par diverger du code.
 *
 * Prérequis : la société doit avoir son exercice et ses séquences
 * (CompanyAccountingProvisioning), puisque les documents sont émis pour de bon.
 */
final class WorkspaceDemoDataService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly DocumentWriteService $documents,
    ) {}

    public function seedForCompany(Company $company): void
    {
        if (Document::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
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
        $partners = $this->seedPartners($company);
        $products = $this->seedCatalog();

        $this->seedDocuments($partners, $products);
        $this->seedCashMovements($company);
    }

    /**
     * Répertoire des tiers, créé AVANT les documents : chacun s'y rattache par
     * `partner_id` et fige la raison sociale correspondante.
     *
     * @return array<string, Partner>
     */
    private function seedPartners(Company $company): array
    {
        $rows = [
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

        $partners = [];

        foreach ($rows as $index => $row) {
            $partners[$row['legal_name']] = Partner::create([
                'type' => $row['type'],
                'legal_name' => $row['legal_name'],
                'city' => $row['city'],
                'rc_city' => $row['city'],
                // ICE fictif mais valide (15 chiffres) et unique par société.
                'ice' => str_pad((string) (100000000000 + $index), 15, '0', STR_PAD_LEFT),
                'payment_terms_days' => $row['terms'],
                'currency' => $company->default_currency,
            ]);
        }

        return $partners;
    }

    /**
     * Catalogue de démonstration : un cabinet de services numériques marocain,
     * avec des prix HT crédibles et deux taux de TVA différents — sans quoi le
     * récapitulatif de TVA par taux du pied de facture n'aurait rien à montrer.
     *
     * @return array<string, Product>
     */
    private function seedCatalog(): array
    {
        $standard = $this->taxRate('20.00');
        $reduced = $this->taxRate('10.00');

        $categories = [];

        // « Prestation » et « Maintenance » viennent de la nomenclature posée par
        // CompanyCatalogProvisioning : firstOrCreate les REPREND au lieu d'en
        // créer des homonymes, que l'index unique sur lower(name) refuserait de
        // toute façon. Les deux dernières sont propres au jeu de démonstration.
        foreach ([
            ['name' => 'Prestation', 'color' => '#2563EB'],
            ['name' => 'Maintenance', 'color' => '#059669'],
            ['name' => 'Licences & abonnements', 'color' => '#7C3AED'],
            ['name' => 'Matériel', 'color' => '#DC2626'],
        ] as $row) {
            $categories[$row['name']] = Category::query()->firstOrCreate(
                ['name' => $row['name']],
                ['color' => $row['color']],
            );
        }

        $rows = [
            ['name' => 'Journée de conseil', 'reference' => 'CONS-J', 'category' => 'Prestation', 'type' => ProductType::Service, 'unit' => 'jour', 'price' => 450_000, 'cost' => 200_000, 'tax' => $standard],
            ['name' => 'Développement sur mesure', 'reference' => 'DEV-H', 'category' => 'Prestation', 'type' => ProductType::Service, 'unit' => 'heure', 'price' => 60_000, 'cost' => 28_000, 'tax' => $standard],
            ['name' => 'Maintenance applicative', 'reference' => 'MNT-M', 'category' => 'Maintenance', 'type' => ProductType::Service, 'unit' => 'mois', 'price' => 350_000, 'cost' => 150_000, 'tax' => $standard],
            ['name' => 'Licence XPR Facture', 'reference' => 'LIC-XPR', 'category' => 'Licences & abonnements', 'type' => ProductType::Service, 'unit' => 'an', 'price' => 1_200_000, 'cost' => null, 'tax' => $standard],
            ['name' => 'Hébergement mutualisé', 'reference' => 'HEB-M', 'category' => 'Licences & abonnements', 'type' => ProductType::Service, 'unit' => 'mois', 'price' => 45_000, 'cost' => 18_000, 'tax' => $reduced],
            ['name' => 'Ordinateur portable 14"', 'reference' => 'MAT-PC14', 'category' => 'Matériel', 'type' => ProductType::Good, 'unit' => 'unité', 'price' => 950_000, 'cost' => 780_000, 'tax' => $standard],
            ['name' => 'Écran 27" 4K', 'reference' => 'MAT-E27', 'category' => 'Matériel', 'type' => ProductType::Good, 'unit' => 'unité', 'price' => 320_000, 'cost' => 245_000, 'tax' => $standard],
        ];

        $products = [];

        foreach ($rows as $row) {
            $products[$row['reference']] = Product::create([
                'category_id' => $categories[$row['category']]->id,
                'type' => $row['type']->value,
                'reference' => $row['reference'],
                'name' => $row['name'],
                'unit' => $row['unit'],
                'unit_price_cents' => $row['price'],
                'cost_price_cents' => $row['cost'],
                'tax_rate_id' => $row['tax']?->id,
                // Seuls les biens sont suivis : la contrainte CHECK en base
                // refuserait un service coché.
                'track_stock' => $row['type'] === ProductType::Good,
            ]);
        }

        return $products;
    }

    /**
     * Documents de démonstration, ÉMIS DANS L'ORDRE CHRONOLOGIQUE : un numéro
     * plus élevé correspond à une émission plus tardive, ce qu'un contrôle
     * fiscal attend.
     *
     * @param  array<string, Partner>  $partners
     * @param  array<string, Product>  $products
     */
    private function seedDocuments(array $partners, array $products): void
    {
        $today = Carbon::today();

        $rows = [
            ['client' => 'Société Immobilière Anfa', 'days' => 60, 'status' => DocumentStatus::Cancelled, 'lines' => [['MAT-PC14', '12'], ['MAT-E27', '12']]],
            ['client' => 'Boulangerie Al Fath', 'days' => 45, 'status' => DocumentStatus::Paid, 'lines' => [['HEB-M', '12'], ['MNT-M', '1']]],
            ['client' => 'Atlas Distribution S.A.R.L.', 'days' => 25, 'status' => DocumentStatus::Overdue, 'lines' => [['CONS-J', '6'], ['DEV-H', '30']]],
            ['client' => 'Café Maure', 'days' => 18, 'status' => DocumentStatus::Sent, 'lines' => [['LIC-XPR', '1']]],
            ['client' => 'TechMaroc Solutions', 'days' => 12, 'status' => DocumentStatus::Partial, 'lines' => [['DEV-H', '120'], ['MNT-M', '3']]],
            ['client' => 'Riad Azur', 'days' => 8, 'status' => DocumentStatus::Paid, 'lines' => [['CONS-J', '4'], ['HEB-M', '6']]],
        ];

        foreach ($rows as $row) {
            $issuedAt = $this->daysAgo($row['days']);

            $document = $this->documents->create([
                'type' => DocumentType::Invoice->value,
                'partnerId' => $partners[$row['client']]->id,
                'issuedAt' => $issuedAt->toDateString(),
                'items' => $this->lines($products, $row['lines']),
            ]);

            $document = $this->documents->issue($document, $issuedAt);

            // `cancelled` a son endpoint dédié : il refuse par exemple
            // d'annuler deux fois, ce qu'un simple UPDATE ne verrait pas.
            if ($row['status'] === DocumentStatus::Cancelled) {
                $this->documents->cancel($document);

                continue;
            }

            if ($row['status'] !== DocumentStatus::Sent) {
                $this->documents->changeStatus($document, $row['status']);
            }
        }

        // Un devis en attente d'acceptation : l'écran Devis doit avoir de quoi
        // montrer la conversion vers une facture.
        $quote = $this->documents->create([
            'type' => DocumentType::Quote->value,
            'partnerId' => $partners['Consulting RH Maghreb']->id,
            'issuedAt' => $this->daysAgo(5)->toDateString(),
            'dueAt' => $today->copy()->addDays(25)->toDateString(),
            'items' => $this->lines($products, [['CONS-J', '10'], ['MNT-M', '6']]),
        ]);

        $this->documents->changeStatus(
            $this->documents->issue($quote, $this->daysAgo(5)),
            DocumentStatus::Accepted,
        );

        // Un brouillon : sans numéro tant qu'il n'est pas émis (§3).
        $this->documents->create([
            'type' => DocumentType::Invoice->value,
            'clientName' => 'Studio Créatif Casablanca',
            'items' => $this->lines($products, [['DEV-H', '25']]),
        ]);
    }

    /**
     * Traduit `[référence, quantité]` en payload de lignes.
     *
     * Rien d'autre n'est transmis : label, unité, prix et taux sont hérités du
     * produit par DocumentItemBuilder. C'est précisément ce que fait
     * l'interface quand on choisit un article dans la liste.
     *
     * @param  array<string, Product>  $products
     * @param  list<array{0: string, 1: string}>  $lines
     * @return list<array<string, mixed>>
     */
    private function lines(array $products, array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'productId' => $products[$line[0]]->id,
            'quantity' => $line[1],
        ], $lines);
    }

    private function taxRate(string $rate): ?TaxRate
    {
        return TaxRate::query()
            ->whereNull('company_id')
            ->where('kind', 'standard')
            ->where('rate', $rate)
            ->first();
    }

    private function seedCashMovements(Company $company): void
    {
        $today = Carbon::today();

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
                'currency' => $company->default_currency,
            ]);
        }
    }
}
