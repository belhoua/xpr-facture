<?php

declare(strict_types=1);

use App\Modules\Accounting\Enums\TaxKind;
use App\Modules\Accounting\Models\TaxRate;
use App\Modules\Authentication\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Shared\Exceptions\ProblemDetailsRenderer;
use App\Modules\Tenancy\Exceptions\TenantContextMissing;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\Request;

use function Pest\Laravel\actingAs;

/**
 * Le compte AUTHENTIFIÉ mais rattaché à AUCUNE société.
 *
 * État atteignable de plusieurs façons : une inscription interrompue entre la
 * création du compte et celle de la société, un retrait de la dernière
 * appartenance, une invitation jamais acceptée. Il est rare — il n'est pas
 * théorique, et c'est exactement le genre de compte sur lequel une application
 * multi-tenant se met à répondre n'importe quoi.
 *
 * Deux exigences, dans cet ordre :
 *   1. il ne voit RIEN — ni les données d'une autre société, ni un agrégat
 *      calculé sur toute la base ;
 *   2. il n'obtient jamais un 500 : l'API dit ce qui manque.
 */
function detachedUser(): User
{
    return User::factory()->create(['default_company_id' => null]);
}

/**
 * Reproduit l'état que `SetTenantContext` laisse derrière lui pour un compte
 * détaché : utilisateur authentifié, aucune société activée.
 *
 * `actingAs()` ne suffit pas — il renseigne le garde d'authentification, pas le
 * TenantContext, que seul le middleware alimente au fil d'une vraie requête
 * HTTP. Sans ce montage, une assertion Eloquent directe s'exécuterait dans le
 * contexte d'une console (userId null), où l'absence de filtre est voulue : le
 * test passerait sans rien prouver du cas qu'il prétend couvrir.
 */
function actAsDetached(User $user): void
{
    actingAs($user);
    app(TenantContext::class)->authenticateUser((string) $user->getKey());
}

/** Société tierce peuplée, dont le compte détaché ne doit rien apercevoir. */
function otherCompanyWithData(): Company
{
    $company = Company::factory()->create();

    app(TenantContext::class)->runForCompany($company->id, function (): void {
        Partner::factory()->count(3)->create();
        Document::factory()->count(2)->create();
    });

    return $company;
}

it('ne laisse filtrer aucune donnée d’une autre société', function (): void {
    otherCompanyWithData();
    $user = detachedUser();

    // Sans contexte tenant, le scope global ne filtrait rien du tout : la
    // requête partait sans clause `company_id` et ramenait la base entière.
    // C'est la régression que ce cas verrouille.
    actAsDetached($user);

    expect(Partner::query()->count())->toBe(0)
        ->and(Document::query()->count())->toBe(0);
});

it('ne voit du référentiel partagé que les lignes globales', function (): void {
    $company = otherCompanyWithData();

    // Taux propre à la société tierce, qui ne doit PAS être visible ; les taux
    // globaux du TaxRateSeeder, eux, restent légitimes.
    app(TenantContext::class)->runForCompany($company->id, function (): void {
        // `rate` est un pourcentage décimal (numeric(5,2)), pas des centièmes.
        TaxRate::query()->create([
            'rate' => '12.50',
            'label_fr' => 'Taux maison',
            'label_ar' => 'نسبة خاصة',
            'kind' => TaxKind::Standard,
        ]);
    });

    actAsDetached(detachedUser());

    $rates = TaxRate::query()->get();

    expect($rates)->not->toBeEmpty()
        ->and($rates->pluck('company_id')->filter()->all())->toBe([])
        ->and($rates->pluck('label_fr')->all())->not->toContain('Taux maison');
});

it('refuse les écrans métier par la permission, pas par une panne', function (): void {
    $user = detachedUser();

    // Sans société, le compte n'a AUCUN rôle : le middleware `permission`
    // tranche avant que le contrôleur — et son requireCompany() — ne soit
    // atteint. C'est le comportement réel, et il est correct : 403, pas 500.
    actingAs($user)
        ->getJson('/api/v1/users')
        ->assertForbidden();
});

it('sert le bootstrap du frontend sans société active', function (): void {
    $user = detachedUser();

    // `/auth/me` est la seule route tenant sans permission : c'est par elle que
    // le front apprend qui est connecté. Elle doit répondre même détachée,
    // sinon l'application n'a aucun moyen de dire ce qui manque.
    actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('active_company', null)
        ->assertJsonPath('companies', []);
});

it('rend TenantContextMissing en 409, jamais en 500', function (): void {
    // Aucune route n'y mène aujourd'hui — `permission` les couvre toutes —
    // mais tout appel à requireId() depuis un contrôleur ou un FormRequest
    // sans permission y tomberait. Le rendu est donc verrouillé ici, à la
    // source, plutôt qu'à travers une route qui pourrait changer.
    $response = ProblemDetailsRenderer::render(
        new TenantContextMissing,
        Request::create('/api/v1/documents'),
    );

    expect($response?->getStatusCode())->toBe(409);
});

it('ne renvoie jamais 500 sur les écrans de lecture', function (string $uri): void {
    otherCompanyWithData();
    $user = detachedUser();

    $response = actingAs($user)->getJson($uri);

    // 200 (liste vide) ou 4xx explicite : tout sauf une panne serveur. Le code
    // exact dépend des permissions — un compte sans société n'a aucun rôle,
    // donc aucune permission — mais aucun de ces cas n'est un 5xx.
    expect($response->status())->toBeLessThan(500);
})->with([
    '/api/v1/documents',
    '/api/v1/partners',
    '/api/v1/products',
    '/api/v1/projects',
    '/api/v1/cash',
    '/api/v1/dashboard/stats',
    '/api/v1/conventions',
    '/api/v1/admin-notes',
]);
