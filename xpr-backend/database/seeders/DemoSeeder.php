<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Documents\Services\DocumentCalculator;
use App\Modules\Tenancy\Models\Company;
use Database\Factories\CashMovementFactory;
use Database\Factories\DocumentFactory;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed de démonstration complet.
 *
 * Stratégie RLS : les tables `documents` et `cash_movements` ont le Row Level
 * Security activé. Laravel se connecte en superuser (seeder), donc FORCE RLS
 * s'applique. On contourne en posant `SET LOCAL app.company_id = '...'` dans
 * une transaction avant chaque lot d'insertions — exactement comme le fait le
 * middleware SetTenantContext en production.
 *
 * On utilise DB::table() (query builder) et non l'ORM pour les entités tenant
 * afin d'éviter le GlobalScope `BelongsToCompany` qui appelle TenantContext
 * non chargé pendant le seed.
 */
final class DemoSeeder extends Seeder
{
    // ── Données fixes pour la société de démo ─────────────────────────────

    /** E-mail du propriétaire (owner) — mot de passe : `password` */
    private const OWNER_EMAIL = 'kamal.bennani@almaghrib-demo.ma';

    /** Sociétés fictives marocaines de démo. */
    private const COMPANIES = [
        [
            'legal_name' => 'Société Al Maghrib S.A.R.L.',
            'trade_name' => 'Al Maghrib',
            'legal_form' => 'sarl',
            'ice' => '001234567890123',
            'if_number' => '12345678',
            'rc_number' => '123456',
            'rc_city' => 'Casablanca',
            'vat_regime' => 'debit',
            'vat_exempt' => false,
            'share_capital' => 10_000_000_00, // 10 000 000 DH en centimes
            'address' => '45, Bd Anfa, 7ème étage',
            'city' => 'Casablanca',
            'country' => 'MA',
            'phone' => '+212 522 000 001',
            'email' => 'contact@almaghrib-demo.ma',
            'default_currency' => 'MAD',
            'timezone' => 'Africa/Casablanca',
        ],
        [
            'legal_name' => 'Atlas Technologies S.A.R.L.',
            'trade_name' => 'AtlasTech',
            'legal_form' => 'sarl',
            'ice' => '009876543210987',
            'if_number' => '87654321',
            'rc_number' => '654321',
            'rc_city' => 'Rabat',
            'vat_regime' => 'debit',
            'vat_exempt' => false,
            'share_capital' => 5_000_000_00,
            'address' => '12, Av. Mohammed V',
            'city' => 'Rabat',
            'country' => 'MA',
            'phone' => '+212 537 000 002',
            'email' => 'info@atlastech-demo.ma',
            'default_currency' => 'MAD',
            'timezone' => 'Africa/Casablanca',
        ],
    ];

    /** Collaborateurs de démo avec leur rôle dans Al Maghrib (1ère société). */
    private const TEAM = [
        [
            'name' => 'Kamal Bennani',
            'email' => self::OWNER_EMAIL,
            'locale' => 'fr',
            'role' => 'owner',
        ],
        [
            'name' => 'Yasmine Alaoui',
            'email' => 'yasmine.alaoui@almaghrib-demo.ma',
            'locale' => 'fr',
            'role' => 'admin',
        ],
        [
            'name' => 'Mourad Tazi',
            'email' => 'mourad.tazi@almaghrib-demo.ma',
            'locale' => 'fr',
            'role' => 'accountant',
        ],
        [
            'name' => 'Salma Berrada',
            'email' => 'salma.berrada@almaghrib-demo.ma',
            'locale' => 'ar',
            'role' => 'sales',
        ],
        [
            'name' => 'Othmane Idrissi',
            'email' => 'o.idrissi@almaghrib-demo.ma',
            'locale' => 'fr',
            'role' => 'viewer',
        ],
        // Utilisateur en attente (invitation non acceptée)
        [
            'name' => 'Nadia Cherkaoui',
            'email' => 'n.cherkaoui@cabinet-externe.ma',
            'locale' => 'fr',
            'role' => 'accountant',
            'pending' => true,
        ],
    ];

    // ── Distribution des statuts de factures ──────────────────────────────

    /**
     * Répartition des 22 factures pour Al Maghrib.
     * Représente un pipeline commercial réaliste.
     */
    private const INVOICE_DISTRIBUTION = [
        'paid' => 8,
        'sent' => 5,
        'partial' => 3,
        'overdue' => 3,
        'draft' => 2,
        'cancelled' => 1,
    ];

    /** Notes de démo couvrant les trois statuts et les trois priorités. */
    private const ADMIN_NOTES = [
        [
            'subject' => 'Problème de synchronisation TVA',
            'body' => "Les taux de TVA ne se synchronisent pas lors de l'import des factures d'achat : le taux 20 % ressort parfois à 0 %. Merci de vérifier la configuration.",
            'priority' => 'high',
            'status' => 'open',
            'days_ago' => 2,
        ],
        [
            'subject' => "Demande d'accès au rapport annuel 2025",
            'body' => "Nous souhaitons activer l'export PDF du rapport annuel consolidé pour l'exercice 2025. Cette fonctionnalité est-elle incluse dans notre abonnement ?",
            'priority' => 'normal',
            'status' => 'answered',
            'days_ago' => 10,
        ],
        [
            'subject' => 'Suggestion : filtre par échéance sur les factures',
            'body' => "Pouvoir filtrer les factures par plage d'échéances directement dans le tableau, sans passer par un export, ferait gagner beaucoup de temps au quotidien.",
            'priority' => 'low',
            'status' => 'closed',
            'days_ago' => 22,
        ],
    ];

    public function run(): void
    {
        // ── 0. Vider le cache des permissions Spatie ───────────────────────
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // ── 1. Créer les deux sociétés de démo ────────────────────────────
        [$companyMain, $companySecond] = $this->createCompanies();

        // ── 2. Créer les utilisateurs et les rattacher à la société principale ─
        $this->createTeam($companyMain);

        // ── 3. Répertoire des tiers — AVANT les factures, qui s'y rattachent
        $this->seedPartners($companyMain->id, 18);
        $this->seedPartners($companySecond->id, 6);

        // ── 4. Catalogue — AVANT les documents, dont les lignes le référencent
        $this->seedCatalog($companyMain->id);
        $this->seedCatalog($companySecond->id);

        // ── 5. Documents pour Al Maghrib (société principale) ──────────────
        $this->seedDocuments($companyMain->id);

        // ── 5bis. Quelques documents pour AtlasTech (société secondaire) ───
        $this->seedDocuments($companySecond->id, 5);

        // ── 6. Mouvements de caisse pour Al Maghrib ───────────────────────
        $this->seedCashMovements($companyMain->id, 25);

        // ── 7. Quelques mouvements pour AtlasTech ─────────────────────────
        $this->seedCashMovements($companySecond->id, 8);

        // ── 8. Notes adressées au support ─────────────────────────────────
        $this->seedAdminNotes($companyMain->id);

        $this->command->info('✅ DemoSeeder terminé.');
        $this->command->table(
            ['Ressource', 'Société', 'Quantité'],
            [
                ['Utilisateurs', 'Al Maghrib', count(self::TEAM)],
                ['Factures',     'Al Maghrib', array_sum(self::INVOICE_DISTRIBUTION)],
                ['Factures',     'AtlasTech',  5],
                ['Tiers',        'Al Maghrib', 18],
                ['Tiers',        'AtlasTech',  6],
                ['Mouvements',   'Al Maghrib', 25],
                ['Mouvements',   'AtlasTech',  8],
                ['Notes',        'Al Maghrib', count(self::ADMIN_NOTES)],
            ]
        );
    }

    // ── Méthodes privées ──────────────────────────────────────────────────

    /**
     * Crée les deux sociétés de démo et retourne leurs modèles.
     *
     * @return array{0: Company, 1: Company}
     */
    private function createCompanies(): array
    {
        $now = now();
        $companies = [];

        foreach (self::COMPANIES as $data) {
            // Idempotence par lecture-puis-écriture, et NON par upsert : la table
            // n'a délibérément pas d'unicité sur l'ICE (cf. migration
            // create_companies_table — une société peut exister pour elle-même
            // ET chez son cabinet comptable). Un ON CONFLICT ('ice') échouerait
            // donc en SQLSTATE 42P10.
            $existing = DB::table('companies')->where('ice', $data['ice'])->first();

            if ($existing === null) {
                DB::table('companies')->insert(
                    array_merge($data, ['created_at' => $now, 'updated_at' => $now]),
                );
            } else {
                DB::table('companies')
                    ->where('id', $existing->id)
                    ->update(array_merge($data, ['updated_at' => $now]));
            }

            $companies[] = Company::where('ice', $data['ice'])->firstOrFail();
        }

        return $companies;
    }

    /**
     * Crée les utilisateurs de l'équipe et les attache à la société principale.
     * Le premier utilisateur (owner) est également rattaché à AtlasTech.
     */
    private function createTeam(Company $company): void
    {
        $now = now();

        foreach (self::TEAM as $member) {
            // Idempotent : on retrouve ou crée.
            $user = User::withTrashed()->firstOrCreate(
                ['email' => $member['email']],
                [
                    'name' => $member['name'],
                    'locale' => $member['locale'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => $now,
                    'default_company_id' => $company->id,
                ]
            );

            // Restaurer si soft-deleted (re-run du seeder).
            if ($user->trashed()) {
                $user->restore();
            }

            // Assigner le rôle Spatie (contexte team = company).
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            $role = Role::findByName($member['role'], 'web');
            $user->syncRoles([$role]);
            // Réinitialiser le contexte team.
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);

            // Attacher au pivot company_user si pas encore fait.
            $alreadyAttached = DB::table('company_user')
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->exists();

            if (! $alreadyAttached) {
                $isPending = $member['pending'] ?? false;

                DB::table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'invited_by' => null,
                    'invited_at' => $now,
                    'joined_at' => $isPending ? null : $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Catalogue de la société : catégories puis articles.
     *
     * Seedé AVANT les documents, dont chaque ligne référence un article et en
     * recopie le libellé, l'unité, le prix et le taux de TVA — exactement comme
     * le fait DocumentItemBuilder en production.
     *
     * @return list<array{id: string, name: string, unit: string, price: int, tax_rate_id: ?string, tax_rate: string}>
     */
    private function seedCatalog(string $companyId): array
    {
        $now = now();

        // Deux taux différents dans le catalogue, délibérément : sans cela le
        // récapitulatif de TVA par taux du pied de facture (§3) n'aurait qu'une
        // seule ligne et ne prouverait rien.
        $standard = $this->globalTaxRate('20.00');
        $reduced = $this->globalTaxRate('10.00');

        $catalog = [
            ['category' => 'Prestations', 'color' => '#2563EB', 'name' => 'Journée de conseil', 'reference' => 'CONS-J', 'type' => 'service', 'unit' => 'jour', 'price' => 450_000, 'cost' => 200_000, 'tax' => $standard],
            ['category' => 'Prestations', 'color' => '#2563EB', 'name' => 'Développement sur mesure', 'reference' => 'DEV-H', 'type' => 'service', 'unit' => 'heure', 'price' => 60_000, 'cost' => 28_000, 'tax' => $standard],
            ['category' => 'Prestations', 'color' => '#2563EB', 'name' => 'Formation utilisateurs', 'reference' => 'FORM-J', 'type' => 'service', 'unit' => 'jour', 'price' => 380_000, 'cost' => 160_000, 'tax' => $standard],
            ['category' => 'Licences & abonnements', 'color' => '#7C3AED', 'name' => 'Licence XPR Facture', 'reference' => 'LIC-XPR', 'type' => 'service', 'unit' => 'an', 'price' => 1_200_000, 'cost' => null, 'tax' => $standard],
            ['category' => 'Licences & abonnements', 'color' => '#7C3AED', 'name' => 'Hébergement mutualisé', 'reference' => 'HEB-M', 'type' => 'service', 'unit' => 'mois', 'price' => 45_000, 'cost' => 18_000, 'tax' => $reduced],
            ['category' => 'Maintenance', 'color' => '#4B5563', 'name' => 'Maintenance applicative', 'reference' => 'MNT-M', 'type' => 'service', 'unit' => 'mois', 'price' => 350_000, 'cost' => 150_000, 'tax' => $standard],
            ['category' => 'Matériel informatique', 'color' => '#059669', 'name' => 'Ordinateur portable 14"', 'reference' => 'MAT-PC14', 'type' => 'good', 'unit' => 'unité', 'price' => 950_000, 'cost' => 780_000, 'tax' => $standard],
            ['category' => 'Matériel informatique', 'color' => '#059669', 'name' => 'Écran 27" 4K', 'reference' => 'MAT-E27', 'type' => 'good', 'unit' => 'unité', 'price' => 320_000, 'cost' => 245_000, 'tax' => $standard],
            ['category' => 'Fournitures de bureau', 'color' => '#D97706', 'name' => 'Ramette papier A4', 'reference' => 'FRN-A4', 'type' => 'good', 'unit' => 'ramette', 'price' => 5_500, 'cost' => 3_800, 'tax' => $standard],
        ];

        // Idempotence : une société déjà pourvue n'est pas re-seedée, on relit
        // simplement son catalogue pour composer les lignes de documents.
        if (DB::table('products')->where('company_id', $companyId)->exists()) {
            return $this->readCatalog($companyId);
        }

        $categoryIds = [];
        $categoryRows = [];
        $productRows = [];

        foreach ($catalog as $article) {
            if (! isset($categoryIds[$article['category']])) {
                $categoryIds[$article['category']] = Str::uuid7()->toString();
                $categoryRows[] = [
                    'id' => $categoryIds[$article['category']],
                    'company_id' => $companyId,
                    'name' => $article['category'],
                    'color' => $article['color'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $productRows[] = [
                'id' => Str::uuid7()->toString(),
                'company_id' => $companyId,
                'category_id' => $categoryIds[$article['category']],
                'type' => $article['type'],
                'reference' => $article['reference'],
                'name' => $article['name'],
                'unit' => $article['unit'],
                'unit_price_cents' => $article['price'],
                'cost_price_cents' => $article['cost'],
                'currency' => 'MAD',
                'tax_rate_id' => $article['tax']['id'] ?? null,
                // Seuls les biens sont suivis : la contrainte CHECK
                // `products_stock_goods_only_check` refuserait un service coché.
                'track_stock' => $article['type'] === 'good',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Contournement RLS : SET LOCAL dans une transaction dédiée.
        DB::transaction(function () use ($companyId, $categoryRows, $productRows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('categories')->insert($categoryRows);
            DB::table('products')->insert($productRows);
        });

        return $this->readCatalog($companyId);
    }

    /**
     * Relit le catalogue d'une société, joint à son taux de TVA.
     *
     * Le taux est ramené ICI plutôt que sur chaque ligne de document : les
     * lignes en ont besoin pour figer `tax_rate`, et faire une requête par
     * ligne sur une cinquantaine de documents serait absurde.
     *
     * @return list<array{id: string, name: string, unit: string, price: int, tax_rate_id: ?string, tax_rate: string}>
     */
    private function readCatalog(string $companyId): array
    {
        $rows = DB::table('products')
            ->leftJoin('tax_rates', 'tax_rates.id', '=', 'products.tax_rate_id')
            ->where('products.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->orderBy('products.name')
            ->get([
                'products.id',
                'products.name',
                'products.unit',
                'products.unit_price_cents',
                'products.tax_rate_id',
                'tax_rates.rate',
            ]);

        return array_values($rows->map(fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'unit' => (string) $row->unit,
            'price' => (int) $row->unit_price_cents,
            'tax_rate_id' => $row->tax_rate_id === null ? null : (string) $row->tax_rate_id,
            // Sans taux rattaché, la ligne est à 0 % — une décision, pas un vide.
            'tax_rate' => $row->rate === null ? '0.00' : (string) $row->rate,
        ])->all());
    }

    /** @return array{id: string}|null */
    private function globalTaxRate(string $rate): ?array
    {
        $id = DB::table('tax_rates')
            ->whereNull('company_id')
            ->where('kind', 'standard')
            ->where('rate', $rate)
            ->value('id');

        return is_string($id) ? ['id' => $id] : null;
    }

    /**
     * Insère `$total` documents pour une société donnée en contournant le RLS.
     *
     * La répartition par statut suit INVOICE_DISTRIBUTION pour la société
     * principale ; pour les sociétés secondaires on génère aléatoirement.
     *
     * Chaque document porte de VRAIES lignes, calculées par DocumentCalculator.
     * Les totaux de l'en-tête en découlent — jamais l'inverse : un jeu de démo
     * dont le TTC ne serait pas la somme de ses lignes rendrait le pied de
     * facture incohérent dès le premier écran ouvert.
     */
    private function seedDocuments(string $companyId, int $total = 0): void
    {
        // Idempotence (cf. docblock de DatabaseSeeder : `db:seed` doit pouvoir
        // être relancé sans gonfler le jeu de données). Les documents n'ont pas
        // de clé naturelle stable ici : on ne re-seede pas une société déjà
        // pourvue plutôt que de dupliquer.
        if (DB::table('documents')->where('company_id', $companyId)->exists()) {
            return;
        }

        $catalog = $this->readCatalog($companyId);

        if ($catalog === []) {
            return;
        }

        $factory = DocumentFactory::new();
        $rows = [];
        $items = [];
        $now = now();

        $statuses = $total === 0
            ? array_merge(...array_map(
                static fn (string $status, int $count): array => array_fill(0, $count, $status),
                array_keys(self::INVOICE_DISTRIBUTION),
                array_values(self::INVOICE_DISTRIBUTION),
            ))
            // Sociétés secondaires : distribution aléatoire, laissée à la factory.
            : array_fill(0, $total, null);

        foreach ($statuses as $status) {
            [$row, $rowItems] = $this->buildDocument($factory, $companyId, $status, $catalog, $now);
            $rows[] = $row;
            $items = [...$items, ...$rowItems];
        }

        $rows = $this->numberChronologically($rows, $companyId);

        // Contournement RLS : SET LOCAL dans une transaction dédiée.
        DB::transaction(function () use ($companyId, $rows, $items): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('documents')->insert($rows);
            DB::table('document_items')->insert($items);
        });
    }

    /**
     * Attribue les numéros dans l'ordre chronologique d'émission et avance la
     * séquence d'autant.
     *
     * La factory ne numérote plus : elle ignore la séquence de la société. Ici
     * on reproduit ce que fait une vraie validation — numéro croissant avec la
     * date, aucun trou — puis on positionne `next_number` pour que la PREMIÈRE
     * facture créée depuis l'interface enchaîne sans retomber sur un numéro
     * déjà pris.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function numberChronologically(array $rows, string $companyId): array
    {
        ['id' => $fiscalYearId, 'year' => $year] = $this->openFiscalYear($companyId);

        // Les brouillons restent sans numéro (§3) et ne consomment rien.
        $numbered = array_values(array_filter(
            array_keys($rows),
            static fn (int $index): bool => $rows[$index]['status'] !== 'draft',
        ));

        usort(
            $numbered,
            static fn (int $a, int $b): int => strcmp(
                (string) $rows[$a]['issued_at'],
                (string) $rows[$b]['issued_at'],
            ),
        );

        foreach ($numbered as $rank => $index) {
            $rows[$index]['number'] = sprintf('FAC-%s-%04d', $year, $rank + 1);
        }

        DB::table('sequences')
            ->where('company_id', $companyId)
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('document_type', 'invoice')
            ->update(['next_number' => count($numbered) + 1, 'updated_at' => now()]);

        return $rows;
    }

    /**
     * Exercice courant de la société, créé avec ses séquences s'il n'existe pas.
     * Les sociétés de démo sont insérées en SQL brut, sans passer par
     * CompanyProvisioning : leur initialisation comptable se fait donc ici.
     *
     * @return array{id: string, year: string}
     */
    private function openFiscalYear(string $companyId): array
    {
        $now = now();
        $label = $now->format('Y');

        $existing = DB::table('fiscal_years')
            ->where('company_id', $companyId)
            ->where('label', $label)
            ->value('id');

        if (is_string($existing)) {
            return ['id' => $existing, 'year' => $label];
        }

        DB::transaction(function () use ($companyId, $now): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");

            $fiscalYearId = DB::table('fiscal_years')->insertGetId([
                'company_id' => $companyId,
                'label' => $now->format('Y'),
                'starts_on' => $now->copy()->startOfYear()->toDateString(),
                'ends_on' => $now->copy()->endOfYear()->toDateString(),
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ], 'id');

            foreach (['invoice', 'quote', 'credit_note'] as $type) {
                DB::table('sequences')->insert([
                    'company_id' => $companyId,
                    'fiscal_year_id' => $fiscalYearId,
                    'document_type' => $type,
                    'format' => match ($type) {
                        'quote' => 'DEV-{YYYY}-{0000}',
                        'credit_note' => 'AV-{YYYY}-{0000}',
                        default => 'FAC-{YYYY}-{0000}',
                    },
                    'next_number' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        $id = DB::table('fiscal_years')
            ->where('company_id', $companyId)
            ->where('label', $label)
            ->value('id');

        return ['id' => (string) $id, 'year' => $label];
    }

    /**
     * Répertoire de tiers d'une société : clients, fournisseurs, et quelques
     * fiches à la fois l'un et l'autre — le cas est courant et l'écran doit le
     * montrer.
     *
     * Même stratégie que les factures : query builder + SET LOCAL pour franchir
     * la RLS hors requête HTTP, et non-réexécution si la société est déjà
     * pourvue (`db:seed` doit rester rejouable).
     */
    private function seedPartners(string $companyId, int $count): void
    {
        if (DB::table('partners')->where('company_id', $companyId)->exists()) {
            return;
        }

        $factory = PartnerFactory::new();
        $now = now();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            // Un tiers sur six est client ET fournisseur.
            $state = match (true) {
                $i % 6 === 5 => $factory->both(),
                $i % 3 === 2 => $factory->supplier(),
                default => $factory->client(),
            };

            /** @var array<string, mixed> $attrs */
            $attrs = $state->make()->toArray();

            $rows[] = array_merge($attrs, [
                'company_id' => $companyId,
                // Code lisible, séquentiel par société : c'est ce que les
                // cabinets utilisent pour retrouver une fiche au clavier.
                'code' => sprintf('T-%03d', $i + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::transaction(function () use ($companyId, $rows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('partners')->insert($rows);
        });
    }

    /**
     * Notes de support de la société principale. Le propriétaire en est
     * l'auteur — `created_by` est nullable mais renseigné ici pour rester
     * représentatif de la production.
     */
    private function seedAdminNotes(string $companyId): void
    {
        if (DB::table('admin_notes')->where('company_id', $companyId)->exists()) {
            return;
        }

        $ownerId = DB::table('users')->where('email', self::OWNER_EMAIL)->value('id');
        $now = now();

        $rows = [];

        foreach (self::ADMIN_NOTES as $note) {
            $createdAt = $now->copy()->subDays($note['days_ago']);

            $rows[] = [
                'company_id' => $companyId,
                'created_by' => $ownerId,
                'subject' => $note['subject'],
                'body' => $note['body'],
                'priority' => $note['priority'],
                'status' => $note['status'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        // Contournement RLS : SET LOCAL dans une transaction dédiée.
        DB::transaction(function () use ($companyId, $rows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('admin_notes')->insert($rows);
        });
    }

    /**
     * Construit l'en-tête ET les lignes d'un document.
     *
     * L'identifiant est généré ici, en UUID v7 comme le fait la base : les
     * lignes doivent pointer vers leur document AVANT que l'insertion groupée
     * n'ait lieu, et une insertion ligne à ligne pour récupérer les clés
     * multiplierait les allers-retours par cinquante.
     *
     * @param  string|null  $status  NULL = aléatoire via definition()
     * @param  list<array{id: string, name: string, unit: string, price: int, tax_rate_id: ?string, tax_rate: string}>  $catalog
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function buildDocument(
        DocumentFactory $factory,
        string $companyId,
        ?string $status,
        array $catalog,
        Carbon $now,
    ): array {
        /** @var array<string,mixed> $attrs */
        $attrs = $status
            ? $factory->$status()->make()->toArray()
            : $factory->make()->toArray();

        $documentId = Str::uuid7()->toString();
        $partner = $this->pickClient($companyId);

        [$items, $totals] = $this->buildDocumentItems($documentId, $companyId, $catalog, $now);

        return [
            array_merge($attrs, [
                'id' => $documentId,
                'company_id' => $companyId,
                'partner_id' => $partner['id'] ?? null,
                // Nom FIGÉ depuis la raison sociale du tiers : c'est ce que fait
                // DocumentWriteService en production. Sans cette copie, la démo
                // afficherait un nom sans rapport avec le tiers rattaché.
                'client_name' => $partner['legal_name'] ?? $attrs['client_name'],
                // Les totaux de la factory sont ÉCRASÉS : ceux qui font foi
                // sont la somme des lignes qu'on vient de composer.
                'subtotal_cents' => $totals['subtotalCents'],
                'discount_cents' => $totals['discountCents'],
                'tax_cents' => $totals['taxCents'],
                'total_cents' => $totals['totalCents'],
                'created_at' => $now,
                'updated_at' => $now,
            ]),
            $items,
        ];
    }

    /**
     * Compose 1 à 4 lignes tirées du catalogue et en calcule les montants.
     *
     * Le calcul passe par DocumentCalculator, le même service que la
     * production : si la règle d'arrondi change un jour, le jeu de démo suit
     * sans qu'on ait à y penser.
     *
     * @param  list<array{id: string, name: string, unit: string, price: int, tax_rate_id: ?string, tax_rate: string}>  $catalog
     * @return array{0: list<array<string, mixed>>, 1: array{subtotalCents: int, discountCents: int, taxCents: int, totalCents: int}}
     */
    private function buildDocumentItems(string $documentId, string $companyId, array $catalog, Carbon $now): array
    {
        $calculator = app(DocumentCalculator::class);

        $picked = (array) array_rand($catalog, min(random_int(1, 4), count($catalog)));
        $items = [];
        $lines = [];

        foreach ($picked as $position => $index) {
            /** @var array{id: string, name: string, unit: string, price: int, tax_rate_id: ?string, tax_rate: numeric-string} $article */
            $article = $catalog[$index];

            $quantity = number_format((float) random_int(1, 20), 3, '.', '');
            // Une ligne sur cinq porte une remise : assez pour que la colonne
            // « remise » ne soit pas systématiquement vide à l'écran.
            $discount = random_int(1, 5) === 1 ? '10.00' : '0.00';

            $amounts = $calculator->line($quantity, $article['price'], $discount, $article['tax_rate']);

            $items[] = [
                'id' => Str::uuid7()->toString(),
                'company_id' => $companyId,
                'document_id' => $documentId,
                'product_id' => $article['id'],
                'position' => $position,
                'label' => $article['name'],
                'quantity' => $quantity,
                'unit' => $article['unit'],
                'unit_price_cents' => $article['price'],
                'discount_percent' => $discount,
                'tax_rate_id' => $article['tax_rate_id'],
                'tax_rate' => $article['tax_rate'],
                'subtotal_cents' => $amounts['subtotalCents'],
                'discount_cents' => $amounts['discountCents'],
                'tax_cents' => $amounts['taxCents'],
                'total_cents' => $amounts['totalCents'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $lines[] = [
                'subtotalCents' => $amounts['subtotalCents'],
                'discountCents' => $amounts['discountCents'],
                'taxCents' => $amounts['taxCents'],
                'totalCents' => $amounts['totalCents'],
            ];
        }

        return [$items, $calculator->totals($lines)];
    }

    /**
     * Un client au hasard parmi ceux de la société, pour rattacher une facture.
     *
     * Les fiches sont mises en cache par société : le seeder construit des
     * dizaines de lignes et relire la table à chaque facture serait inutilement
     * bavard. Retourne null si le répertoire est vide — la facture garde alors
     * le nom libre de la factory.
     *
     * @var array<string, list<array{id: string, legal_name: string}>>
     */
    private array $clientCache = [];

    /** @return array{id: string, legal_name: string}|null */
    private function pickClient(string $companyId): ?array
    {
        if (! isset($this->clientCache[$companyId])) {
            $rows = DB::table('partners')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('type', ['client', 'both'])
                ->get(['id', 'legal_name']);

            // array_values : `Collection::values()->all()` ne suffit pas à
            // convaincre l'analyse statique qu'on obtient bien une `list`.
            $this->clientCache[$companyId] = array_values($rows
                ->map(fn (object $row): array => [
                    'id' => (string) $row->id,
                    'legal_name' => (string) $row->legal_name,
                ])
                ->all());
        }

        $clients = $this->clientCache[$companyId];

        return $clients === [] ? null : $clients[array_rand($clients)];
    }

    /**
     * Insère `$count` mouvements de caisse pour une société donnée.
     */
    private function seedCashMovements(string $companyId, int $count): void
    {
        // Même garde d'idempotence que seedDocuments().
        if (DB::table('cash_movements')->where('company_id', $companyId)->exists()) {
            return;
        }

        $factory = CashMovementFactory::new();
        $rows = [];
        $now = now();

        for ($i = 0; $i < $count; $i++) {
            /** @var array<string,mixed> $attrs */
            $attrs = $factory->make()->toArray();
            $rows[] = array_merge($attrs, [
                'company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Contournement RLS : SET LOCAL dans une transaction dédiée.
        DB::transaction(function () use ($companyId, $rows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('cash_movements')->insert($rows);
        });
    }
}
