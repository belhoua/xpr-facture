<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Écran Services : la vue métier du catalogue restreinte à `type = service`,
 * et la remise par défaut qu'elle introduit.
 *
 * Ce qui est vérifié ici et nulle part ailleurs : la remise reste dans ses
 * bornes, elle traverse l'API sans perdre sa précision décimale, et la
 * nomenclature de départ est bien posée à la création d'une société.
 */

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function servicePayload(array $overrides = []): array
{
    return array_merge([
        'type' => 'service',
        'reference' => 'SER-001',
        'name' => 'Audit de conformité DGI',
        'unit' => 'forfait',
        'unitPriceCents' => 1_500_000,
    ], $overrides);
}

// ── Nomenclature de départ ────────────────────────────────────────────────

it('dote une société neuve des natures de service par défaut', function (): void {
    [$user] = workspaceAccount();

    /** @var list<array<string, mixed>> $rows */
    $rows = actingAs($user)->getJson('/api/v1/categories')->json('data');

    // Elles remplacent le champ « type » d'un formulaire figé : la société les
    // renomme ou en ajoute, sans migration (§3).
    expect(array_column($rows, 'name'))
        ->toContain('Prestation', 'Conseil', 'Maintenance', 'Forfait');
});

it('ne duplique pas les catégories quand le jeu de démonstration les réutilise', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);

    // Le provisioning pose « Prestation », le jeu de démonstration y range ses
    // articles : firstOrCreate doit reprendre la ligne, pas en créer une
    // seconde que l'index unique sur lower(name) refuserait.
    expect(Category::query()->where('name', 'Prestation')->count())->toBe(1)
        ->and(Category::query()->where('name', 'Maintenance')->count())->toBe(1);

    actingAs($user)->getJson('/api/v1/categories')->assertOk();
});

// ── Remise par défaut ─────────────────────────────────────────────────────

it('enregistre une remise par défaut sur un service', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->postJson('/api/v1/products', servicePayload(['defaultDiscountPercent' => 12.5]))
        ->assertCreated()
        // Chaîne et non nombre : la valeur est recopiée telle quelle sur la
        // ligne de document, sans repasser par un flottant.
        ->assertJsonPath('defaultDiscountPercent', '12.50');
});

it('applique zéro quand aucune remise n’est transmise', function (): void {
    [$user] = workspaceAccount();

    // « Pas de remise » et « remise nulle » désignent la même chose : la
    // colonne est NOT NULL avec défaut à 0, jamais nullable.
    actingAs($user)
        ->postJson('/api/v1/products', servicePayload())
        ->assertCreated()
        ->assertJsonPath('defaultDiscountPercent', '0.00');
});

it('retombe sur zéro quand le formulaire transmet null', function (): void {
    [$user] = workspaceAccount();

    // Le front envoie `null` pour « champ laissé vide » ; sans le repli du
    // ProductService, PostgreSQL rejetterait l'INSERT en 23502.
    actingAs($user)
        ->postJson('/api/v1/products', servicePayload(['defaultDiscountPercent' => null]))
        ->assertCreated()
        ->assertJsonPath('defaultDiscountPercent', '0.00');
});

it('refuse une remise hors des bornes 0–100', function (float $value): void {
    [$user] = workspaceAccount();

    // Mêmes bornes que `document_items_discount_check` : une valeur que la
    // ligne de document refuserait n'a pas à être stockable sur la fiche.
    actingAs($user)
        ->postJson('/api/v1/products', servicePayload(['defaultDiscountPercent' => $value]))
        ->assertStatus(422)
        ->assertJsonPath('errors.defaultDiscountPercent.0', fn (string $m): bool => $m !== '');
})->with([-1.0, 100.01, 250.0]);

it('refuse une remise à plus de deux décimales', function (): void {
    [$user] = workspaceAccount();

    // La colonne est DECIMAL(5,2) : accepter 12.345 stockerait un arrondi
    // silencieux que l'utilisateur ne verrait qu'à la relecture.
    actingAs($user)
        ->postJson('/api/v1/products', servicePayload(['defaultDiscountPercent' => 12.345]))
        ->assertStatus(422);
});

it('remet la remise à zéro par une mise à jour partielle', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $service = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/products/{$service->id}", ['defaultDiscountPercent' => 7])
        ->assertOk()
        ->assertJsonPath('defaultDiscountPercent', '7.00');

    actingAs($user)
        ->patchJson("/api/v1/products/{$service->id}", ['defaultDiscountPercent' => 0])
        ->assertOk()
        ->assertJsonPath('defaultDiscountPercent', '0.00');
});

it('ne touche pas aux documents déjà émis quand la remise catalogue change', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $service = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    $before = DB::table('document_items')
        ->where('product_id', $service->id)
        ->orderBy('id')
        ->pluck('discount_percent');

    actingAs($user)
        ->patchJson("/api/v1/products/{$service->id}", ['defaultDiscountPercent' => 40])
        ->assertOk();

    // La fiche fournit une VALEUR DE SAISIE, pas une règle de prix : les lignes
    // en figent une copie à l'émission (§3, immuabilité).
    $after = DB::table('document_items')
        ->where('product_id', $service->id)
        ->orderBy('id')
        ->pluck('discount_percent');

    expect($after->all())->toBe($before->all());
});

// ── Héritage sur la ligne de document ─────────────────────────────────────

it('hérite la remise du service quand la ligne n’en transmet pas', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $service = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/products/{$service->id}", ['defaultDiscountPercent' => 15])
        ->assertOk();

    // L'héritage est fait par le SERVEUR (DocumentItemBuilder), pas seulement
    // pré-rempli par le formulaire : l'API doit se comporter correctement pour
    // un client qui n'est pas notre interface (§10).
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Occasionnel',
            'items' => [['productId' => $service->id, 'quantity' => '2']],
        ])
        ->assertCreated()
        ->assertJsonPath('items.0.discountPercent', '15.00');
});

it('laisse la ligne primer sur la remise du service', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    $service = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    actingAs($user)
        ->patchJson("/api/v1/products/{$service->id}", ['defaultDiscountPercent' => 15])
        ->assertOk();

    // Une remise transmise à 0 est une décision commerciale explicite : elle
    // doit primer, sinon le vendeur ne pourrait pas retirer la remise habituelle.
    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'clientName' => 'Client Occasionnel',
            'items' => [['productId' => $service->id, 'quantity' => '2', 'discountPercent' => 0]],
        ])
        ->assertCreated()
        ->assertJsonPath('items.0.discountPercent', '0.00');
});

// ── Vue Services ──────────────────────────────────────────────────────────

it('ne remonte que des services quand la liste est filtrée', function (): void {
    [$user] = workspaceAccount();

    /** @var list<array<string, mixed>> $rows */
    $rows = actingAs($user)
        ->getJson('/api/v1/products?type=service')
        ->assertOk()
        ->json('data');

    expect($rows)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($rows, 'type'))))->toBe(['service']);
});

it('cherche un service par sa référence', function (): void {
    [$user] = workspaceAccount();

    // La barre de recherche de l'écran Services couvre le nom ET la référence.
    actingAs($user)
        ->getJson('/api/v1/products?type=service&search=MNT-M')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Maintenance applicative');
});

it('isole les services entre deux sociétés', function (): void {
    [, $companyA] = workspaceAccount();
    [$userB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyA->id);
    $serviceOfA = Product::query()->where('reference', 'CONS-J')->firstOrFail();

    // Exigence §5.6, rejouée sur le chemin d'accès propre à cet écran.
    /** @var list<array<string, mixed>> $rowsOfB */
    $rowsOfB = actingAs($userB)->getJson('/api/v1/products?type=service')->json('data');

    expect(array_column($rowsOfB, 'id'))->not->toContain($serviceOfA->id);

    actingAs($userB)
        ->patchJson("/api/v1/products/{$serviceOfA->id}", ['defaultDiscountPercent' => 90])
        ->assertNotFound();
});
