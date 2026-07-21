<?php

declare(strict_types=1);

use App\Modules\Invoices\Models\Invoice;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Verrouille la convention de résolution des modèles tenant.
 *
 * SubstituteBindings appartient au groupe `api` et s'exécute donc AVANT les
 * middlewares ajoutés par route ('auth:sanctum', 'tenant'). Un binding
 * implicite `Invoice $invoice` résoudrait le modèle à ce moment-là, hors scope
 * de société : la facture d'autrui serait servie. Les contrôleurs reçoivent
 * pour cette raison un identifiant `string` et résolvent eux-mêmes.
 *
 * Ce test échouerait si quelqu'un « simplifiait » une signature de contrôleur
 * en type-hintant le modèle — c'est précisément ce qu'il protège.
 */
it('ne résout pas une facture appartenant à une autre société', function (): void {
    [$userA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    // Facture de B, lue hors scope pour récupérer son identifiant.
    app(TenantContext::class)->activateCompany($companyB->id);
    $invoiceOfB = Invoice::query()->whereNotNull('number')->firstOrFail();
    app(TenantContext::class)->forget();

    // A tente d'y accéder par son identifiant : chaque verbe doit répondre 404,
    // jamais 200 ni 403 — l'existence même de la ressource ne doit pas fuiter.
    actingAs($userA)
        ->patchJson("/api/v1/invoices/{$invoiceOfB->id}", [
            'clientName' => 'Détournée',
            'issuedAt' => now()->toDateString(),
            'dueAt' => now()->addMonth()->toDateString(),
            'status' => 'draft',
            'totalCents' => 100000,
            'currency' => 'MAD',
        ])
        ->assertNotFound();

    actingAs($userA)->deleteJson("/api/v1/invoices/{$invoiceOfB->id}")->assertNotFound();
    actingAs($userA)->postJson("/api/v1/invoices/{$invoiceOfB->id}/cancel")->assertNotFound();

    // Et la facture de B est intacte.
    app(TenantContext::class)->activateCompany($companyB->id);
    expect(Invoice::query()->find($invoiceOfB->id))->not->toBeNull();
});
