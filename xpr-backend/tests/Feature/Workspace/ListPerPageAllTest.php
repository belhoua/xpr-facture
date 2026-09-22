<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * `perPage=all` sur les listes documents/partenaires/projets (2026-09-22, à
 * la demande de l'exploitant) : plus de découpage à l'écran par défaut, mais
 * le plafond réel (5000, `*Service::paginate()`) reste un garde-fou — jamais
 * une valeur réellement illimitée sur le VPS 1 vCPU (§16 CLAUDE.md).
 */
it('renvoie la totalité des documents avec perPage=all, au-delà de la page par défaut', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Document::factory()->count(30)->quote()->draft()->create([
        'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
    ]);

    // 8 pièces de démonstration + 30 ajoutées ici : au-delà des 25 par défaut
    // ET de l'ancien plafond de 100.
    actingAs($user)
        ->getJson('/api/v1/documents')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 25)
        ->assertJsonCount(25, 'data');

    actingAs($user)
        ->getJson('/api/v1/documents?perPage=all')
        ->assertOk()
        ->assertJsonPath('meta.total', 38)
        ->assertJsonCount(38, 'data');
});

it('renvoie la totalité des partenaires avec perPage=all', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Partner::factory()->client()->count(30)->create(['ice' => null]);

    $total = Partner::query()->count();

    actingAs($user)
        ->getJson('/api/v1/partners?perPage=all')
        ->assertOk()
        ->assertJsonPath('meta.total', $total)
        ->assertJsonCount($total, 'data');
});

it('renvoie la totalité des projets avec perPage=all', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);
    Project::factory()->count(30)->create([
        'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
    ]);

    $total = Project::query()->count();

    actingAs($user)
        ->getJson('/api/v1/projects?perPage=all')
        ->assertOk()
        ->assertJsonPath('meta.total', $total)
        ->assertJsonCount($total, 'data');
});

// ── Le plafond reste réel, jamais une valeur illimitée ──────────────────────

it('borne perPage à 5000 même sur une valeur numérique absurde', function (): void {
    [$user] = workspaceAccount();

    actingAs($user)
        ->getJson('/api/v1/documents?perPage=999999999')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 5000);

    actingAs($user)
        ->getJson('/api/v1/partners?perPage=999999999')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 5000);

    actingAs($user)
        ->getJson('/api/v1/projects?perPage=999999999')
        ->assertOk()
        ->assertJsonPath('meta.perPage', 5000);
});
