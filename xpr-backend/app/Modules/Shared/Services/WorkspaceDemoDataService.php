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
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
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
        $this->seedCashMovements($company, $partners);
        $this->seedProjects($partners);
    }

    /**
     * Projets d'avancement et livrables remis.
     *
     * Les quatre états sont représentés, chacun avec un avancement cohérent :
     * un écran de démonstration où tout serait « en cours » ne montrerait ni
     * les badges, ni le comportement d'un projet clos. Les livrables ne sont
     * posés que sur les projets qui en ont produit — un projet annulé n'a rien
     * remis, et lui en inventer un rendrait la démonstration trompeuse.
     *
     * @param  array<string, Partner>  $partners
     */
    private function seedProjects(array $partners): void
    {
        $today = Carbon::today();

        $rows = [
            [
                'partner' => 'Société Immobilière Anfa',
                'title' => 'Résidence Al Manar — lot A',
                'status' => 'in_progress',
                'progress' => 65,
                'description' => 'Contrôle technique et suivi de chantier, 42 logements.',
                'deliverables' => [
                    ['title' => 'Notice technique', 'days' => 90],
                    ['title' => 'Rapport d\'avancement n°1', 'days' => 45],
                    ['title' => 'Rapport d\'avancement n°2', 'days' => 12],
                ],
            ],
            [
                'partner' => 'Atlas Distribution S.A.R.L.',
                'title' => 'Entrepôt logistique Zenata',
                'status' => 'completed',
                'progress' => 100,
                'description' => 'Mission achevée, dossier remis au maître d\'ouvrage.',
                'deliverables' => [
                    ['title' => 'Notice technique', 'days' => 150],
                    ['title' => 'Procès-verbal de réception', 'days' => 20],
                ],
            ],
            [
                'partner' => 'TechMaroc Solutions',
                'title' => 'Extension usine Aïn Sebaâ',
                'status' => 'monitoring',
                'progress' => 100,
                'description' => 'Période de garantie : réserves en cours de levée.',
                'deliverables' => [
                    ['title' => 'Procès-verbal de réception', 'days' => 60],
                ],
            ],
            [
                'partner' => 'Riad Azur',
                'title' => 'Villa Palmeraie — suivi de chantier',
                'status' => 'canceled',
                'progress' => 15,
                'description' => 'Projet abandonné par le maître d\'ouvrage.',
                'deliverables' => [],
            ],
        ];

        foreach ($rows as $row) {
            $partner = $partners[$row['partner']] ?? null;

            if (! $partner instanceof Partner) {
                continue;
            }

            $project = Project::create([
                'partner_id' => $partner->id,
                'title' => $row['title'],
                'status' => $row['status'],
                'progress_percentage' => $row['progress'],
                'description' => $row['description'],
            ]);

            foreach ($row['deliverables'] as $deliverable) {
                Deliverable::create([
                    'project_id' => $project->id,
                    'title' => $deliverable['title'],
                    'delivery_date' => $today->copy()->subDays($deliverable['days']),
                ]);
            }
        }
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
            ['name' => 'Contrôle technique', 'color' => '#DC2626'],
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
            ['name' => 'Licence logicielle BCAT', 'reference' => 'LIC-BCAT', 'category' => 'Licences & abonnements', 'type' => ProductType::Service, 'unit' => 'an', 'price' => 1_200_000, 'cost' => null, 'tax' => $standard],
            ['name' => 'Hébergement mutualisé', 'reference' => 'HEB-M', 'category' => 'Licences & abonnements', 'type' => ProductType::Service, 'unit' => 'mois', 'price' => 45_000, 'cost' => 18_000, 'tax' => $reduced],
            ['name' => 'Mission de contrôle technique', 'reference' => 'CTC-M', 'category' => 'Contrôle technique', 'type' => ProductType::Service, 'unit' => 'mission', 'price' => 950_000, 'cost' => 780_000, 'tax' => $standard],
            ['name' => 'Visite de chantier', 'reference' => 'CTC-V', 'category' => 'Contrôle technique', 'type' => ProductType::Service, 'unit' => 'intervention', 'price' => 320_000, 'cost' => 245_000, 'tax' => $standard],
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
                // Jamais suivi en stock : le jeu de démonstration ne contient
                // plus que des SERVICES depuis le 2026-08-18, et
                // `products_stock_goods_only_check` refuserait un service
                // coché. La comparaison au type qui figurait ici est tombée
                // avec le dernier bien — PHPStan la signalait comme toujours
                // fausse, ce qu'elle était devenue.
                'track_stock' => false,
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
            ['client' => 'Société Immobilière Anfa', 'days' => 60, 'status' => DocumentStatus::Cancelled, 'lines' => [['CTC-M', '12'], ['CTC-V', '12']]],
            ['client' => 'Boulangerie Al Fath', 'days' => 45, 'status' => DocumentStatus::Paid, 'lines' => [['HEB-M', '12'], ['MNT-M', '1']]],
            ['client' => 'Atlas Distribution S.A.R.L.', 'days' => 25, 'status' => DocumentStatus::Overdue, 'lines' => [['CONS-J', '6'], ['DEV-H', '30']]],
            ['client' => 'Café Maure', 'days' => 18, 'status' => DocumentStatus::Sent, 'lines' => [['LIC-BCAT', '1']]],
            ['client' => 'TechMaroc Solutions', 'days' => 12, 'status' => DocumentStatus::Partial, 'lines' => [['DEV-H', '120'], ['MNT-M', '3']]],
            ['client' => 'Riad Azur', 'days' => 8, 'status' => DocumentStatus::Paid, 'lines' => [['CONS-J', '4'], ['HEB-M', '6']]],
        ];

        foreach ($rows as $row) {
            $issuedAt = $this->daysAgo($row['days']);

            // Depuis le 2026-08-14, `create()` numérote la facture et la pose
            // en `sent` : l'appel à `issue()` qui suivait est devenu inutile —
            // et fautif, puisqu'il répond 409 sur un document déjà numéroté.
            $document = $this->documents->create([
                'type' => DocumentType::Invoice->value,
                'partnerId' => $partners[$row['client']]->id,
                'issuedAt' => $issuedAt->toDateString(),
                'items' => $this->lines($products, $row['lines']),
            ]);

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

        $this->documents->changeStatus($quote, DocumentStatus::Accepted);

        // Une facture à client libre — sans fiche tiers au répertoire. Elle
        // naît numérotée comme les autres depuis le 2026-08-14 ; l'écran n'a
        // plus de brouillon à montrer, puisque le produit n'en crée plus.
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

    /**
     * Journal de caisse.
     *
     * Les ENCAISSEMENTS portent leur tiers, les DÉCAISSEMENTS n'en ont pas :
     * un loyer et un achat de fournitures ne s'adressent à aucun client du
     * répertoire. C'est ce que l'écran doit savoir montrer, et une démonstration
     * où tout serait rattaché ne l'exercerait jamais.
     *
     * @param  array<string, Partner>  $partners
     */
    private function seedCashMovements(Company $company, array $partners): void
    {
        $today = Carbon::today();

        $movements = [
            ['occurred_at' => $today->copy()->subDays(2), 'partner' => 'Riad Azur', 'label' => 'Encaissement Riad Azur', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => 3200000],
            ['occurred_at' => $today->copy()->subDays(5), 'partner' => null, 'label' => 'Achat fournitures bureau', 'method' => 'cash', 'register_name' => 'Caisse principale', 'amount_cents' => -45000],
            ['occurred_at' => $today->copy()->subDays(8), 'partner' => 'TechMaroc Solutions', 'label' => 'Acompte TechMaroc', 'method' => 'cheque', 'register_name' => 'Caisse principale', 'amount_cents' => 4000000],
            ['occurred_at' => $today->copy()->subDays(12), 'partner' => null, 'label' => 'Paiement loyer', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => -850000],
            ['occurred_at' => $today->copy()->subDays(15), 'partner' => 'Boulangerie Al Fath', 'label' => 'Encaissement Boulangerie Al Fath', 'method' => 'cash', 'register_name' => 'Petite caisse', 'amount_cents' => 980000],
            ['occurred_at' => $today->copy()->subDays(19), 'partner' => 'Société Immobilière Anfa', 'label' => 'Encaissement Société Immobilière Anfa', 'method' => 'cheque', 'register_name' => 'Caisse principale', 'amount_cents' => 5400000],
            ['occurred_at' => $today->copy()->subDays(24), 'partner' => 'Atlas Distribution S.A.R.L.', 'label' => 'Acompte Atlas Distribution', 'method' => 'transfer', 'register_name' => 'Caisse principale', 'amount_cents' => 2750000],
        ];

        foreach ($movements as $row) {
            $partnerName = $row['partner'];
            $partner = $partnerName === null ? null : ($partners[$partnerName] ?? null);

            unset($row['partner']);

            CashMovement::create([
                ...$row,
                'partner_id' => $partner instanceof Partner ? $partner->id : null,
                'currency' => $company->default_currency,
            ]);
        }
    }
}
