<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Sequence;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

/**
 * Rattrapage des compteurs (`xpr:resync-sequences`) sur des données où un
 * numéro a été posé hors séquence AVANT le déploiement de
 * `DocumentNumberService::syncFromManualNumber()` — le cas concret qui a
 * motivé la commande : une séquence en retard sur un numéro déjà attribué.
 */

/** @param  array<string, mixed>  $options */
function resync(array $options): PendingCommand
{
    $command = artisan('xpr:resync-sequences', $options);

    expect($command)->toBeInstanceOf(PendingCommand::class);

    /** @var PendingCommand $command */
    return $command;
}

it('recale un compteur en retard sur le MAX réel de la base', function (): void {
    [$user, $company] = workspaceAccount();
    Company::query()->whereKey($company->id)->update(['legal_name' => 'BCAT']);
    app(TenantContext::class)->activateCompany($company->id);

    $sequence = Sequence::query()->where('document_type', 'quote')->firstOrFail();
    $year = $sequence->fiscalYear->label;

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'quote',
            'clientName' => 'Client Recalage',
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 100_000]],
        ])
        ->assertCreated()
        ->json('id');

    // Simule un numéro déjà présent en base (import, correction directe) que
    // le compteur, lui, ignore totalement.
    DB::table('documents')
        ->where('company_id', $company->id)
        ->where('type', 'quote')
        ->orderByDesc('created_at')
        ->limit(1)
        ->update(['number' => "DEV-{$year}-0150"]);

    resync(['--force' => true])->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe(151);
});

it('ne touche à rien en dry-run', function (): void {
    [$user, $company] = workspaceAccount();
    Company::query()->whereKey($company->id)->update(['legal_name' => 'BCAT']);
    app(TenantContext::class)->activateCompany($company->id);

    $sequence = Sequence::query()->where('document_type', 'quote')->firstOrFail();
    $year = $sequence->fiscalYear->label;

    actingAs($user)
        ->postJson('/api/v1/documents', [
            'type' => 'quote',
            'clientName' => 'Client Recalage',
            'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 100_000]],
        ])
        ->assertCreated();

    // Capturé APRÈS la création normale (qui a légitimement avancé le
    // compteur) et AVANT la divergence simulée : c'est cette valeur que
    // --dry-run doit laisser intacte.
    $before = $sequence->fresh()->next_number;

    DB::table('documents')
        ->where('company_id', $company->id)
        ->where('type', 'quote')
        ->orderByDesc('created_at')
        ->limit(1)
        ->update(['number' => "DEV-{$year}-0150"]);

    resync(['--dry-run' => true])->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe($before);
});

it('ne recale rien quand aucun numéro ne dépasse le compteur', function (): void {
    [, $company] = workspaceAccount();
    Company::query()->whereKey($company->id)->update(['legal_name' => 'BCAT']);
    app(TenantContext::class)->activateCompany($company->id);

    $sequence = Sequence::query()->where('document_type', 'quote')->firstOrFail();
    $before = $sequence->next_number;

    resync(['--force' => true])->assertSuccessful();

    expect($sequence->fresh()->next_number)->toBe($before);
});

it('refuse une société ambiguë ou introuvable', function (): void {
    resync(['--company' => 'Société Fantôme', '--force' => true])->assertFailed();
});
