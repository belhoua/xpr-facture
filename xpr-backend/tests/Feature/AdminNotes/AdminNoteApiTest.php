<?php

declare(strict_types=1);

use App\Modules\AdminNotes\Models\AdminNote;
use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/**
 * Monte un compte propriétaire rattaché à une société neuve.
 *
 * @return array{0: User, 1: Company}
 */
function noteAccount(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $user->companies()->attach($company, ['joined_at' => now()]);
    $user->forceFill(['default_company_id' => $company->id])->save();

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $user->assignRole('owner');

    return [$user, $company];
}

/** Crée une note POUR une société donnée, hors requête HTTP. */
function makeNote(Company $company, string $subject): AdminNote
{
    return app(TenantContext::class)->runForCompany(
        $company->id,
        fn (): AdminNote => AdminNote::create([
            'subject' => $subject,
            'body' => 'Corps de la note, suffisamment long pour la validation.',
            'priority' => 'normal',
        ]),
    );
}

it('liste les notes de la société active', function (): void {
    [$user, $company] = noteAccount();
    makeNote($company, 'Problème de synchronisation TVA');

    actingAs($user)
        ->getJson('/api/v1/admin-notes')
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'subject', 'body', 'priority', 'status', 'createdAt']],
        ])
        ->assertJsonPath('data.0.subject', 'Problème de synchronisation TVA')
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.priority', 'normal');
});

it('retourne une liste vide sans note plutôt qu une erreur', function (): void {
    [$user] = noteAccount();

    actingAs($user)
        ->getJson('/api/v1/admin-notes')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('crée une note rattachée à la société active et à son auteur', function (): void {
    [$user, $company] = noteAccount();

    actingAs($user)
        ->postJson('/api/v1/admin-notes', [
            'subject' => 'Demande accès rapport annuel',
            'body' => 'Nous souhaitons activer export PDF du rapport consolidé.',
            'priority' => 'high',
        ])
        ->assertCreated()
        ->assertJsonPath('subject', 'Demande accès rapport annuel')
        ->assertJsonPath('priority', 'high')
        // Statut initial imposé par le serveur, pas par le client.
        ->assertJsonPath('status', 'open');

    $note = AdminNote::withoutGlobalScopes()->firstOrFail();

    expect($note->company_id)->toBe($company->id)
        ->and($note->created_by)->toBe($user->id);
});

it('refuse un statut imposé par le client', function (): void {
    [$user] = noteAccount();

    actingAs($user)
        ->postJson('/api/v1/admin-notes', [
            'subject' => 'Tentative de forçage',
            'body' => 'Le client tente de créer une note déjà close.',
            'priority' => 'low',
            'status' => 'closed',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'open');
});

it('rejette une note invalide', function (): void {
    [$user] = noteAccount();

    actingAs($user)
        ->postJson('/api/v1/admin-notes', [
            'subject' => 'abc',           // < 4 caractères
            'body' => 'trop court',       // < 10 caractères
            'priority' => 'urgent',       // hors enum
        ])
        ->assertStatus(422);
});

it('exige une authentification', function (): void {
    getJson('/api/v1/admin-notes')->assertUnauthorized();
});

it('isole les notes entre deux sociétés (§5.6)', function (): void {
    [$userA, $companyA] = noteAccount();
    [$userB, $companyB] = noteAccount();

    makeNote($companyA, 'Note confidentielle de A');
    makeNote($companyB, 'Note de B');

    actingAs($userA)
        ->getJson('/api/v1/admin-notes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Note confidentielle de A');

    // B ne doit voir que la sienne : ni fuite, ni total gonflé.
    actingAs($userB)
        ->getJson('/api/v1/admin-notes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.subject', 'Note de B');
});
