<?php

declare(strict_types=1);

use App\Modules\Accounting\Services\CompanyAccountingProvisioning;
use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

/**
 * Le cas de la société VIERGE — celui de tout nouveau client.
 *
 * Toutes les autres suites Workspace partent de `workspaceAccount()`, qui monte
 * le jeu de démonstration : elles prouvent que les écrans savent afficher des
 * données, jamais qu'ils savent n'en afficher aucune. Or c'est l'état dans
 * lequel arrive chaque compte réel depuis que le jeu de démonstration ne se
 * déclenche plus à l'inscription (config `xpr.demo_data_on_signup`).
 *
 * Ce que ça attrape, concrètement : une agrégation qui divise par un total nul,
 * un `max()` sur une série vide, une moyenne de tendance sans période de
 * référence. Autant de 500 qui ne se manifestent QUE sur une base neuve —
 * c'est-à-dire au premier écran que voit un client, et jamais chez nous.
 */

/**
 * Compte owner sans la moindre écriture métier.
 *
 * L'exercice et les séquences sont provisionnés — c'est le cas réel : le
 * référentiel comptable existe dès la création de la société, seules les
 * données saisies manquent. Le catalogue par défaut est délibérément OMIS, pour
 * que même les tables de nomenclature soient vides.
 *
 * @return array{0: User, 1: Company}
 */
function emptyWorkspaceAccount(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['joined_at' => now()]);
    $user->forceFill(['default_company_id' => $company->id])->save();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole('owner');

    app(CompanyAccountingProvisioning::class)->initialize($company);

    return [$user, $company];
}

it('sert le tableau de bord d’une société sans aucune écriture', function (): void {
    [$user] = emptyWorkspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/dashboard/stats?period=last30')
        ->assertOk()
        ->assertJsonPath('revenueCents', 0)
        ->assertJsonPath('collectedCents', 0)
        ->assertJsonPath('outstandingCents', 0)
        ->assertJsonPath('overdueCents', 0)
        ->assertJsonPath('overdueCount', 0)
        // La devise reste servie même sans données : l'écran vide affiche
        // quand même ses unités.
        ->assertJsonPath('currency', 'MAD');
});

it('sert le résumé de caisse sans aucun mouvement', function (string $uri): void {
    [$user] = emptyWorkspaceAccount();

    // `/cash` et `/cash/movements` visent le même contrôleur : l'enveloppe
    // porte `movements`, pas `data` — d'où leur exclusion du cas des listes.
    $response = actingAs($user)->getJson($uri)->assertOk();

    expect($response->json('movements'))->toBe([]);
})->with(['/api/v1/cash', '/api/v1/cash/movements']);

/**
 * Toutes les listes de l'espace applicatif, en une passe.
 *
 * Un jeu de données par écran serait plus lisible ; ce serait aussi 12 montages
 * de compte pour une seule assertion chacun. Ce qui est éprouvé ici est
 * uniforme — « répond 200, avec une collection vide » — et le nom du cas porte
 * l'URL, donc l'échec reste localisable.
 */
it('sert toutes les listes applicatives vides', function (string $uri): void {
    [$user] = emptyWorkspaceAccount();

    $response = actingAs($user)->getJson($uri)->assertOk();

    expect($response->json('data'))->toBe([]);
})->with([
    '/api/v1/documents',
    '/api/v1/partners',
    '/api/v1/products',
    '/api/v1/categories',
    '/api/v1/projects',
    '/api/v1/conventions',
    '/api/v1/deposits',
    '/api/v1/admin-notes',
]);

it('sert le résumé des documents sans aucun document', function (): void {
    [$user] = emptyWorkspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/documents/summary')
        ->assertOk();
});

it('sert le compte courant et les référentiels partagés', function (): void {
    [$user] = emptyWorkspaceAccount();

    actingAs($user)->getJson('/api/v1/auth/me')->assertOk();

    // La société vierge n'a pas de collaborateur, mais elle a son propriétaire :
    // une liste de membres vide signalerait que le rattachement a été perdu.
    $members = actingAs($user)->getJson('/api/v1/users')->assertOk();
    expect($members->json('data'))->toHaveCount(1);

    // Les taux de TVA viennent du référentiel système (TaxRateSeeder), pas de
    // la société : ils doivent être servis dès la première facture saisie.
    $taxRates = actingAs($user)->getJson('/api/v1/tax-rates')->assertOk();
    expect($taxRates->json('data'))->not->toBeEmpty();
});
