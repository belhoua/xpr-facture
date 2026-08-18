<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Documents\Models\Document;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Verrouille la convention de résolution des modèles tenant.
 *
 * SubstituteBindings appartient au groupe `api` et s'exécute donc AVANT les
 * middlewares ajoutés par route ('auth:sanctum', 'tenant'). Un binding
 * implicite `Document $document` résoudrait le modèle à ce moment-là, hors
 * scope de société : le document d'autrui serait servi. Les contrôleurs
 * reçoivent pour cette raison un identifiant `string` et résolvent eux-mêmes.
 *
 * Ces tests échoueraient si quelqu'un « simplifiait » une signature de
 * contrôleur en type-hintant le modèle — c'est précisément ce qu'ils protègent.
 */
it('ne résout pas un document appartenant à une autre société', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    // Document de B, lu hors scope pour récupérer son identifiant.
    app(TenantContext::class)->activateCompany($companyB->id);
    $documentOfB = Document::query()->whereNotNull('number')->firstOrFail();
    app(TenantContext::class)->forget();

    // A tente d'y accéder par son identifiant : chaque verbe doit répondre 404,
    // jamais 200 ni 403 — l'existence même de la ressource ne doit pas fuiter.
    actingAs($userA)->getJson("/api/v1/documents/{$documentOfB->id}")->assertNotFound();
    actingAs($userA)
        ->patchJson("/api/v1/documents/{$documentOfB->id}", ['clientName' => 'Détournée'])
        ->assertNotFound();
    actingAs($userA)->deleteJson("/api/v1/documents/{$documentOfB->id}")->assertNotFound();
    actingAs($userA)->postJson("/api/v1/documents/{$documentOfB->id}/issue")->assertNotFound();
    actingAs($userA)->postJson("/api/v1/documents/{$documentOfB->id}/cancel")->assertNotFound();
    actingAs($userA)->postJson("/api/v1/documents/{$documentOfB->id}/convert")->assertNotFound();

    // Et le document de B est intact.
    app(TenantContext::class)->activateCompany($companyB->id);
    expect(Document::query()->find($documentOfB->id))->not->toBeNull();
});

it('ne résout pas un article de catalogue appartenant à une autre société', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    app(TenantContext::class)->activateCompany($companyB->id);
    $productOfB = Product::query()->firstOrFail();
    app(TenantContext::class)->forget();

    actingAs($userA)->getJson("/api/v1/products/{$productOfB->id}")->assertNotFound();
    actingAs($userA)
        ->patchJson("/api/v1/products/{$productOfB->id}", ['unitPriceCents' => 1])
        ->assertNotFound();
    actingAs($userA)->deleteJson("/api/v1/products/{$productOfB->id}")->assertNotFound();
});
