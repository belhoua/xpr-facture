<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Database\Factories\CashMovementFactory;
use Database\Factories\InvoiceFactory;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seed de démonstration complet.
 *
 * Stratégie RLS : les tables `invoices` et `cash_movements` ont le Row Level
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

        // ── 3. Factures pour Al Maghrib (société principale) ───────────────
        $this->seedInvoices($companyMain->id);

        // ── 4. Quelques factures pour AtlasTech (société secondaire) ──────
        $this->seedInvoices($companySecond->id, 5);

        // ── 4bis. Répertoire des tiers ────────────────────────────────────
        $this->seedPartners($companyMain->id, 18);
        $this->seedPartners($companySecond->id, 6);

        // ── 5. Mouvements de caisse pour Al Maghrib ───────────────────────
        $this->seedCashMovements($companyMain->id, 25);

        // ── 6. Quelques mouvements pour AtlasTech ─────────────────────────
        $this->seedCashMovements($companySecond->id, 8);

        // ── 7. Notes adressées au support ─────────────────────────────────
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
     * Insère `$total` factures pour une société donnée en contournant le RLS.
     *
     * La répartition par statut suit INVOICE_DISTRIBUTION pour la société
     * principale ; pour les sociétés secondaires on génère aléatoirement.
     */
    private function seedInvoices(string $companyId, int $total = 0): void
    {
        // Idempotence (cf. docblock de DatabaseSeeder : `db:seed` doit pouvoir
        // être relancé sans gonfler le jeu de données). Les factures n'ont pas
        // de clé naturelle stable ici : on ne re-seede pas une société déjà
        // pourvue plutôt que de dupliquer.
        if (DB::table('invoices')->where('company_id', $companyId)->exists()) {
            return;
        }

        $factory = InvoiceFactory::new();
        $rows = [];
        $now = now();

        if ($total === 0) {
            // Société principale : distribution contrôlée.
            foreach (self::INVOICE_DISTRIBUTION as $status => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $rows[] = $this->buildInvoiceRow($factory, $companyId, $status, $now);
                }
            }
        } else {
            // Sociétés secondaires : distribution aléatoire.
            for ($i = 0; $i < $total; $i++) {
                $rows[] = $this->buildInvoiceRow($factory, $companyId, null, $now);
            }
        }

        $rows = $this->numberChronologically($rows, $companyId);

        // Contournement RLS : SET LOCAL dans une transaction dédiée.
        DB::transaction(function () use ($companyId, $rows): void {
            DB::statement("SET LOCAL app.company_id = '{$companyId}'");
            DB::table('invoices')->insert($rows);
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
     * Construit un tableau de données brutes pour une facture.
     *
     * @param  string|null  $status  NULL = aléatoire via definition()
     * @return array<string, mixed>
     */
    private function buildInvoiceRow(
        InvoiceFactory $factory,
        string $companyId,
        ?string $status,
        Carbon $now,
    ): array {
        /** @var array<string,mixed> $attrs */
        $attrs = $status
            ? $factory->$status()->make()->toArray()
            : $factory->make()->toArray();

        return array_merge($attrs, [
            'company_id' => $companyId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Insère `$count` mouvements de caisse pour une société donnée.
     */
    private function seedCashMovements(string $companyId, int $count): void
    {
        // Même garde d'idempotence que seedInvoices().
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
