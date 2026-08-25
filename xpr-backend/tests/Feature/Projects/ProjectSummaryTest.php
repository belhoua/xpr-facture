<?php

declare(strict_types=1);

use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;

use function Pest\Laravel\actingAs;

/**
 * Comptes de l'écran « Avancement de projet ».
 *
 * Ce que ce fichier atteste, et qui n'est pas évident :
 *
 *  1. les comptes portent sur TOUT le portefeuille filtré, pas sur la page
 *     affichée — c'est la raison d'être de l'endpoint ;
 *  2. la définition de « à compléter » est celle du frontend, au mot près :
 *     description vide OU aucun livrable. Le service n'y entre pas ;
 *  3. les comptes suivent les MÊMES filtres que la liste, sans quoi les cartes
 *     cesseraient de décrire les lignes ;
 *  4. aucun MONTANT n'est exposé — un projet n'est pas une pièce commerciale.
 *
 * Les assertions passent par un CLIENT DÉDIÉ et le filtre `partnerId` : chaque
 * société de test reçoit le jeu de démonstration, qui porte déjà quatre
 * projets. Compter en absolu obligerait à écrire ces quatre-là en dur dans
 * chaque attente, et le premier ajout au jeu de démo casserait tout le fichier
 * sans qu'aucune règle n'ait bougé.
 */

/**
 * Un client de la société active.
 *
 * Défini ici et non emprunté à `ProjectAdvancementTest` : un helper déclaré
 * dans un autre fichier n'existe que si Pest a chargé ce fichier-là, et le test
 * échouerait dès qu'on lance ce seul fichier — ce qu'on fait précisément quand
 * on travaille sur ce module. Le nom est PRÉFIXÉ par le module : les helpers de
 * test vivent dans un espace de noms global, et `summaryClient` était déjà pris
 * par `ClientSituationsSummaryTest`. La collision ne se voit pas quand on lance
 * ce fichier seul — seulement sur la suite complète, en « Cannot redeclare ».
 */
function projectSummaryClient(string $companyId): Partner
{
    app(TenantContext::class)->activateCompany($companyId);

    return Partner::factory()->client()->create(['ice' => null]);
}

/** Un projet de la société active, complété ou non selon les arguments. */
function projectSummaryProject(
    string $companyId,
    string $partnerId,
    string $status,
    ?string $description,
    bool $withDeliverable,
): Project {
    app(TenantContext::class)->activateCompany($companyId);

    $project = Project::factory()->create([
        'company_id' => $companyId,
        'partner_id' => $partnerId,
        'status' => $status,
        // Posé avec le statut : la factory le tire du statut ALÉATOIRE de sa
        // définition, ce qui donnerait un « achevé à 40 % » dès qu'on surcharge
        // l'état sans surcharger l'avancement.
        'progress_percentage' => $status === 'completed' ? 100 : 40,
        'description' => $description,
    ]);

    if ($withDeliverable) {
        // Créé à la main : `deliverables` n'a pas de factory, et `company_id`
        // est de toute façon posé par le trait tenant sous le contexte actif.
        Deliverable::query()->create([
            'project_id' => $project->id,
            'title' => 'Rapport de visite',
            'delivery_date' => now()->toDateString(),
        ]);
    }

    return $project;
}

it('compte les projets par état et par complétude', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectSummaryClient($company->id);

    // Complet et en cours : ni « à compléter », ni « terminé ».
    projectSummaryProject($company->id, $client->id, 'in_progress', 'Suivi du gros œuvre', true);
    // Sans description : à compléter.
    projectSummaryProject($company->id, $client->id, 'in_progress', null, true);
    // Description blanche : à compléter aussi — `btrim` s'en charge, sans quoi
    // une saisie faite d'espaces passerait pour renseignée.
    projectSummaryProject($company->id, $client->id, 'monitoring', '   ', true);
    // Décrit mais sans aucun livrable : à compléter.
    projectSummaryProject($company->id, $client->id, 'completed', 'Mission achevée', false);

    actingAs($user)
        ->getJson('/api/v1/projects/summary?partnerId='.$client->id)
        ->assertOk()
        ->assertJsonPath('count', 4)
        ->assertJsonPath('inProgress', 2)
        ->assertJsonPath('completed', 1)
        ->assertJsonPath('incomplete', 3);
});

it('n’expose AUCUN montant', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectSummaryClient($company->id);
    projectSummaryProject($company->id, $client->id, 'in_progress', 'Décrit', true);

    // Un projet n'a ni total, ni TVA, ni règlement : la charge utile ne porte
    // que des nombres de projets. La garde est explicite parce que la demande
    // de montants revient périodiquement — ce qu'un chantier rapporte se lit
    // sur les documents qui lui sont rattachés, pas ici.
    $payload = actingAs($user)->getJson('/api/v1/projects/summary')->assertOk()->json();

    expect(array_keys($payload))
        ->toEqualCanonicalizing(['count', 'inProgress', 'incomplete', 'completed']);
});

it('compte au-delà de la première page de la liste', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectSummaryClient($company->id);

    app(TenantContext::class)->activateCompany($company->id);
    Project::factory()->count(30)->inProgress()->create([
        'company_id' => $company->id,
        'partner_id' => $client->id,
        'description' => null,
    ]);

    // La liste en rend 25 ; compter ce que l'écran affiche donnerait « 25 » sur
    // un portefeuille qui en compte trente — faux, et faux sans le dire.
    actingAs($user)
        ->getJson('/api/v1/projects?partnerId='.$client->id)
        ->assertOk()
        ->assertJsonCount(25, 'data');

    actingAs($user)
        ->getJson('/api/v1/projects/summary?partnerId='.$client->id)
        ->assertOk()
        ->assertJsonPath('count', 30)
        ->assertJsonPath('incomplete', 30);
});

it('applique les mêmes filtres que la liste', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectSummaryClient($company->id);
    $other = projectSummaryClient($company->id);

    projectSummaryProject($company->id, $client->id, 'in_progress', null, false);
    projectSummaryProject($company->id, $other->id, 'in_progress', 'Décrit', true);

    // Les cartes doivent décrire les lignes en dessous : filtrer la liste par
    // client sans filtrer les comptes afficherait un total qui ne correspond à
    // rien de visible.
    actingAs($user)
        ->getJson('/api/v1/projects/summary?partnerId='.$client->id)
        ->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('incomplete', 1);
});

it('ne compte pas les projets d’une AUTRE société', function (): void {
    [$userA, $companyA] = workspaceAccount();
    [, $companyB] = workspaceAccount();

    $before = actingAs($userA)->getJson('/api/v1/projects/summary')->json('count');

    $clientOfA = projectSummaryClient($companyA->id);
    $clientOfB = projectSummaryClient($companyB->id);

    projectSummaryProject($companyA->id, $clientOfA->id, 'in_progress', 'Décrit', true);
    projectSummaryProject($companyB->id, $clientOfB->id, 'in_progress', null, false);
    projectSummaryProject($companyB->id, $clientOfB->id, 'completed', null, false);

    // Le test d'isolation tenant exigé par §5.6, appliqué à un agrégat : une
    // fuite s'y verrait comme un simple écart de compte, sans qu'aucune donnée
    // d'une autre société n'apparaisse à l'écran. Mesuré en ÉCART parce que le
    // jeu de démonstration a déjà peuplé les deux sociétés.
    $after = actingAs($userA)->getJson('/api/v1/projects/summary')->assertOk()->json('count');

    expect($after - $before)->toBe(1);
});

it('ne compte pas un livrable RETIRÉ comme une fiche complétée', function (): void {
    [$user, $company] = workspaceAccount();
    $client = projectSummaryClient($company->id);

    $project = projectSummaryProject($company->id, $client->id, 'in_progress', 'Décrit', true);

    actingAs($user)
        ->getJson('/api/v1/projects/summary?partnerId='.$client->id)
        ->assertJsonPath('incomplete', 0);

    // Le soft delete du livrable doit refaire basculer la fiche : la
    // sous-requête est du SQL brut, où le global scope ne s'applique pas.
    $project->deliverables()->first()?->delete();

    actingAs($user)
        ->getJson('/api/v1/projects/summary?partnerId='.$client->id)
        ->assertOk()
        ->assertJsonPath('incomplete', 1);
});
