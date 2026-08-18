<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Catalogue produits & services (Étape C). Les règles qui comptent ici :
 * unicité de la référence dans la société, cohérence « un service ne se
 * stocke pas », archivage plutôt que suppression, et cloisonnement.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'service',
        'reference' => 'NEW-001',
        'name' => 'Prestation de test',
        'unit' => 'heure',
        'unitPriceCents' => 75_000,
    ], $overrides);
}

it('crée un service', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/products', productPayload())
        ->assertCreated()
        ->assertJsonPath('type', 'service')
        ->assertJsonPath('unitPriceCents', 75_000)
        ->assertJsonPath('trackStock', false)
        ->assertJsonPath('isActive', true)
        ->assertJsonPath('currency', 'MAD');
});

it('rattache un article à une catégorie et développe son taux de TVA', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $category = Category::query()->where('name', 'Prestation')->firstOrFail();
    $taxRateId = taxRateId('20.00');

    actingAs($user)
        ->postJson('/api/v1/products', productPayload([
            'categoryId' => $category->id,
            'taxRateId' => $taxRateId,
        ]))
        ->assertCreated()
        ->assertJsonPath('categoryName', 'Prestation')
        // Le taux est renvoyé DÉVELOPPÉ : l'éditeur de document doit pouvoir
        // pré-remplir la ligne sans seconde requête.
        ->assertJsonPath('taxRateValue', '20.00');
});

it('calcule la marge unitaire quand le prix de revient est connu', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/products', productPayload(['costPriceCents' => 30_000]))
        ->assertCreated()
        ->assertJsonPath('marginCents', 45_000);
});

it('rend une marge nulle et non zéro sans prix de revient', function (): void {
    [$user] = workspaceAccount();

    // Une marge INCONNUE n'est pas une marge NULLE : l'interface doit pouvoir
    // faire la différence.
    actingAs($user)
        ->postJson('/api/v1/products', productPayload())
        ->assertCreated()
        ->assertJsonPath('marginCents', null);
});

it('refuse une référence déjà prise dans la société', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/products', productPayload(['reference' => 'CONS-J']))
        ->assertStatus(422)
        ->assertJsonPath('errors.reference.0', fn (string $m): bool => $m !== '');
});

it('accepte la même référence dans deux sociétés différentes', function (): void {
    [$userA] = workspaceAccount();
    [$userB] = workspaceAccount();

    // L'unicité est PAR SOCIÉTÉ : deux entreprises ont le droit d'utiliser le
    // même code article.
    actingAs($userA)->postJson('/api/v1/products', productPayload())->assertCreated();
    actingAs($userB)->postJson('/api/v1/products', productPayload())->assertCreated();
});

it('force track_stock à false pour un service', function (): void {
    [$user] = workspaceAccount();

    // Cocher « suivi de stock » puis basculer en service est une séquence
    // naturelle dans un formulaire : on corrige plutôt que de rejeter.
    actingAs($user)
        ->postJson('/api/v1/products', productPayload(['trackStock' => true]))
        ->assertCreated()
        ->assertJsonPath('trackStock', false);
});

it('autorise le suivi de stock sur un bien', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/products', productPayload([
            'type' => 'good',
            'unit' => 'unité',
            'trackStock' => true,
        ]))
        ->assertCreated()
        ->assertJsonPath('trackStock', true);
});

it('modifie partiellement un article', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $product = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    // PATCH partiel : changer le seul prix ne doit pas exiger de renvoyer le
    // type et le libellé.
    actingAs($user)
        ->patchJson("/api/v1/products/{$product->id}", ['unitPriceCents' => 500_000])
        ->assertOk()
        ->assertJsonPath('unitPriceCents', 500_000)
        ->assertJsonPath('name', 'Journée de conseil');
});

it('archive un article sans le supprimer', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $product = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    actingAs($user)->deleteJson("/api/v1/products/{$product->id}")->assertNoContent();

    app(TenantContext::class)->activateCompany($company->id);

    // Les documents qui le référencent doivent rester lisibles : la ligne
    // survit, elle quitte seulement les listes.
    expect(Product::query()->find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('filtre le catalogue par recherche, type et catégorie', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    // Le jeu de démonstration ne contient plus que des SERVICES depuis le
    // 2026-08-18 : la catégorie « Matériel » a suivi et désigne désormais une
    // famille de prestations.
    $controle = Category::query()->where('name', 'Contrôle technique')->firstOrFail();

    actingAs($user)
        ->getJson('/api/v1/products?search=conseil')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', 'CONS-J');

    // Le filtre par type RESTE fonctionnel — c'est lui qu'on éprouve ici, et
    // non l'existence de biens : il n'y en a plus aucun à semer.
    actingAs($user)
        ->getJson('/api/v1/products?type=good')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    actingAs($user)
        ->getJson('/api/v1/products?type=service')
        ->assertOk()
        ->assertJsonPath('data', fn (array $rows): bool => $rows !== []);

    actingAs($user)
        ->getJson("/api/v1/products?categoryId={$controle->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('isole le catalogue entre deux sociétés', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $productOfA = Product::query()->firstOrFail();

    // 404 et non 403 : la société B ne doit pas apprendre que cet article
    // existe (§5).
    actingAs($userB)->getJson("/api/v1/products/{$productOfA->id}")->assertNotFound();
    actingAs($userB)
        ->patchJson("/api/v1/products/{$productOfA->id}", ['unitPriceCents' => 1])
        ->assertNotFound();
});

// ── Catégories ────────────────────────────────────────────────────────────

it('crée une catégorie et compte ses articles', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/categories', ['name' => 'Sous-traitance', 'color' => '#123ABC'])
        ->assertCreated()
        ->assertJsonPath('name', 'Sous-traitance')
        ->assertJsonPath('color', '#123ABC');

    actingAs($user)
        ->getJson('/api/v1/categories')
        ->assertOk()
        // `serviceCount` depuis le 2026-08-18 : l'écran ne présente plus que
        // des catégories de SERVICES, et le compteur ne doit pas annoncer un
        // nombre que la liste ne sait plus montrer.
        ->assertJsonPath('data.0.serviceCount', fn (int $count): bool => $count >= 0);
});

it('refuse deux catégories homonymes, casse comprise', function (): void {
    [$user] = workspaceAccount();

    // L'index en base porte sur `lower(name)` : sans contrôle insensible à la
    // casse, « PRESTATION » passerait la validation puis heurterait l'index,
    // et l'utilisateur recevrait une erreur serveur illisible.
    actingAs($user)
        ->postJson('/api/v1/categories', ['name' => 'PRESTATION'])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', fn (string $m): bool => $m !== '');
});

it('refuse une couleur mal formée', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/categories', ['name' => 'Divers', 'color' => 'rouge'])
        ->assertStatus(422);
});

it('expose le référentiel des taux de TVA', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/tax-rates')
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'labelFr', 'rate', 'kind', 'isDefault', 'isGlobal']]])
        // Le catalogue standard marocain est partagé : il n'appartient à
        // aucune société.
        ->assertJsonPath('data.0.isGlobal', true);
});

it('ne compte QUE les services dans une catégorie', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    // Nom EXPLICITE : la factory puise dans une liste fixe dont le jeu de
    // démonstration se sert aussi, et l'unicité par société la refuserait.
    $category = Category::factory()->create(['name' => 'Catégorie du compteur']);

    Product::factory()->service()->create([
        'company_id' => $company->id,
        'category_id' => $category->id,
    ]);
    // Un bien physique existe encore en base — la société n'en vend plus, mais
    // les jeux de données antérieurs en portent. Il ne doit pas gonfler un
    // compteur intitulé « Services ».
    Product::factory()->good()->create([
        'company_id' => $company->id,
        'category_id' => $category->id,
    ]);

    /** @var list<array<string, mixed>> $rows */
    $rows = actingAs($user)->getJson('/api/v1/categories')->assertOk()->json('data');

    $counts = array_column($rows, 'serviceCount', 'id');

    expect($counts[$category->id])->toBe(1);
});
