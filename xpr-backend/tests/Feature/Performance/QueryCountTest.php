<?php

declare(strict_types=1);

use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Models\Deliverable;
use App\Modules\Projects\Models\Project;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * Garde ANTI N+1 sur les listes principales.
 *
 * ── Pourquoi un test de comptage, et pas un seuil ────────────────────────
 *
 * Le nombre de requêtes d'une liste doit être **constant**, quel que soit le
 * nombre de lignes rendues. Un seuil (« moins de 12 requêtes ») fixerait un
 * chiffre arbitraire qu'il faudrait relever à chaque évolution, et laisserait
 * passer un N+1 tant que le jeu d'essai reste petit. On mesure donc deux fois,
 * avant et après avoir AJOUTÉ des lignes : si le compte bouge, c'est qu'une
 * relation se charge ligne par ligne.
 *
 * ── Ce que ce test ajoute à `preventLazyLoading` ─────────────────────────
 *
 * `AppServiceProvider` interdit déjà l'accès à une relation non chargée hors
 * production, ce qui fait tomber les N+1 par lazy loading. Il ne couvre PAS
 * les requêtes émises volontairement dans une boucle — un `->find()` par ligne
 * dans un service, un compteur recalculé par élément. C'est ce que ce test
 * attrape.
 *
 * ── Le pendant en ÉCRITURE ───────────────────────────────────────────────
 *
 * `tests/Feature/Documents/DocumentQueryBudgetTest.php` tient la même garde
 * sur l'enregistrement d'un document : son coût ne doit pas dépendre du nombre
 * de lignes. Les deux fichiers couvrent les deux moitiés du même risque, et
 * leurs helpers portent des noms distincts — Pest charge tous les tests dans
 * un espace de noms global unique, où deux homonymes font tomber la suite
 * entière.
 */

/**
 * Requêtes SQL émises pendant l'appel.
 *
 * @param  callable(): mixed  $call
 */
function countListQueries(callable $call): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $call();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * Mesure APRÈS préchauffage.
 *
 * Le tout premier appel d'un test paie des requêtes d'amorçage qui ne se
 * rejouent jamais : le registre de permissions de Spatie, la session, les
 * réglages de la société. Les compter fausserait la comparaison dans le sens
 * opposé au défaut cherché — la mesure « avant » sortirait plus haute que la
 * mesure « après », et le test dénoncerait un N+1 là où il n'y a qu'un cache
 * froid.
 *
 * @param  callable(): mixed  $call
 */
function countWarmListQueries(callable $call): int
{
    $call();

    return countListQueries($call);
}

it('garde un nombre de requêtes CONSTANT sur la liste des projets', function (): void {
    [$user, $company] = workspaceAccount();

    $before = countWarmListQueries(
        fn () => actingAs($user)->getJson('/api/v1/projects')->assertOk(),
    );

    // Cinq projets de plus, chacun avec son client et ses livrables : si le nom
    // du client, le service ou le compte de livrables se lisaient ligne par
    // ligne, le compte grimperait de quinze requêtes.
    app(TenantContext::class)->activateCompany($company->id);
    for ($i = 0; $i < 5; $i++) {
        $project = Project::factory()->create([
            'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
        ]);

        Deliverable::query()->create([
            'project_id' => $project->id,
            'title' => "Remise {$i}",
            'delivery_date' => now()->toDateString(),
        ]);
    }

    $after = countWarmListQueries(
        fn () => actingAs($user)->getJson('/api/v1/projects')->assertOk(),
    );

    expect($after)->toBe($before);
});

it('garde un nombre de requêtes CONSTANT sur la liste des documents', function (): void {
    [$user, $company] = workspaceAccount();

    $before = countWarmListQueries(
        fn () => actingAs($user)->getJson('/api/v1/documents')->assertOk(),
    );

    // Le jeu de démonstration a déjà semé des documents ; on en ajoute pour
    // faire varier la taille de la page rendue.
    app(TenantContext::class)->activateCompany($company->id);
    Document::factory()->count(5)->quote()->draft()->create([
        'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
    ]);

    $after = countWarmListQueries(
        fn () => actingAs($user)->getJson('/api/v1/documents')->assertOk(),
    );

    expect($after)->toBe($before);
});

it('garde un nombre de requêtes CONSTANT sur la liste des factures', function (): void {
    [$user, $company] = workspaceAccount();

    $filtered = '/api/v1/documents?type=invoice';

    $before = countWarmListQueries(
        fn () => actingAs($user)->getJson($filtered)->assertOk(),
    );

    app(TenantContext::class)->activateCompany($company->id);
    Document::factory()->count(5)->draft()->create([
        'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
    ]);

    $after = countWarmListQueries(
        fn () => actingAs($user)->getJson($filtered)->assertOk(),
    );

    expect($after)->toBe($before);
});

it('garde un nombre de requêtes CONSTANT sur la liste des devis', function (): void {
    [$user, $company] = workspaceAccount();

    $filtered = '/api/v1/documents?type=quote';

    $before = countWarmListQueries(
        fn () => actingAs($user)->getJson($filtered)->assertOk(),
    );

    app(TenantContext::class)->activateCompany($company->id);
    Document::factory()->count(5)->quote()->draft()->create([
        'partner_id' => Partner::factory()->client()->create(['ice' => null])->id,
    ]);

    $after = countWarmListQueries(
        fn () => actingAs($user)->getJson($filtered)->assertOk(),
    );

    expect($after)->toBe($before);
});
