<?php

declare(strict_types=1);

use App\Modules\Accounting\Models\Sequence;
use App\Modules\Authentication\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/**
 * Remise à blanc d'un espace de travail (`xpr:purge-data`).
 *
 * Une commande destructive se teste par ce qu'elle ÉPARGNE autant que par ce
 * qu'elle supprime : une purge qui emporte le compte ou le paramétrage laisse
 * une base inutilisable, et c'est le genre de dégât qu'on ne découvre qu'à la
 * connexion suivante.
 */
/**
 * Lance la purge et rend la commande en attente, prête à être assertée.
 *
 * `Pest\Laravel\artisan()` est typé `PendingCommand|int` — il rend un entier
 * quand la commande a déjà été exécutée. Ce helper resserre le type en un seul
 * endroit, plutôt que d'annoter chaque appel du fichier.
 *
 * @param  array<string, mixed>  $options
 */
function purge(array $options): PendingCommand
{
    $command = artisan('xpr:purge-data', $options);

    expect($command)->toBeInstanceOf(PendingCommand::class);

    /** @var PendingCommand $command */
    return $command;
}

it('vide les données d’exploitation sans toucher au compte', function (): void {
    [$user, $company] = workspaceAccount();

    // Le jeu de démonstration a déjà peuplé documents, tiers et conventions.
    expect(DB::table('documents')->count())->toBeGreaterThan(0);
    expect(DB::table('partners')->count())->toBeGreaterThan(0);

    purge(['--keep' => $user->email, '--force' => true])
        ->assertSuccessful();

    foreach ([
        'documents', 'document_items', 'payments', 'conventions', 'file_deposits',
        'projects', 'deliverables', 'admin_notes', 'cash_movements', 'partners',
    ] as $table) {
        expect(DB::table($table)->count())->toBe(0, "La table {$table} n'est pas vide.");
    }

    // Le compte, sa société, son rattachement et son rôle : les quatre pièces
    // sans lesquelles il ne peut plus se connecter ni rien faire.
    expect(User::query()->where('email', $user->email)->exists())->toBeTrue();
    expect(DB::table('company_user')->where('user_id', $user->id)->count())->toBe(1);
    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(1);
    expect(DB::table('companies')->where('id', $company->id)->count())->toBe(1);
});

it('conserve le PARAMÉTRAGE et remet les compteurs à 1', function (): void {
    [$user, $company] = workspaceAccount();

    app(TenantContext::class)->activateCompany($company->id);

    // Les séquences ont servi : le jeu de démonstration a numéroté des pièces.
    expect(Sequence::query()->where('next_number', '>', 1)->exists())->toBeTrue();

    purge(['--keep' => $user->email, '--force' => true])
        ->assertSuccessful();

    // Référentiels intacts : ce sont des prérequis FK du schéma, les perdre
    // rendrait toute création de pièce impossible.
    expect(DB::table('fiscal_years')->count())->toBeGreaterThan(0);
    expect(DB::table('tax_rates')->count())->toBeGreaterThan(0);
    expect(DB::table('currencies')->count())->toBeGreaterThan(0);
    expect(DB::table('roles')->count())->toBeGreaterThan(0);
    expect(DB::table('permissions')->count())->toBeGreaterThan(0);

    // Compteurs à 1 et FORMAT conservé : « réinitialiser la numérotation » ne
    // veut pas dire « oublier le paramétrage ». Vider la table aurait donné le
    // même premier numéro en perdant un format personnalisé.
    $quote = Sequence::query()->where('document_type', 'quote')->firstOrFail();

    expect($quote->next_number)->toBe(1);
    expect($quote->format)->toBe('DEV-{YYYY}-{0000}');
    expect(Sequence::query()->where('next_number', '!=', 1)->exists())->toBeFalse();
});

it('fait repartir la numérotation à DEV-2026-0001 et FAC-2026-0001', function (): void {
    [$user, $company] = workspaceAccount();

    purge(['--keep' => $user->email, '--force' => true])
        ->assertSuccessful();

    // La preuve par l'usage : le premier document créé après la purge doit
    // porter le numéro 1. Le millésime vient de l'EXERCICE, pas de la date du
    // jour (§15) — c'est celui de l'exercice ouvert par le provisioning.
    $year = DB::table('fiscal_years')->value('starts_on');
    $year = substr((string) $year, 0, 4);

    app(TenantContext::class)->activateCompany($company->id);
    $client = Partner::factory()->client()->create(['ice' => null]);

    $payload = static fn (string $type): array => [
        'type' => $type,
        'partnerId' => $client->id,
        'items' => [['label' => 'Prestation', 'quantity' => '1', 'unitPriceCents' => 100_000]],
    ];

    Pest\Laravel\actingAs($user)
        ->postJson('/api/v1/documents', $payload('quote'))
        ->assertCreated()
        ->assertJsonPath('number', "DEV-{$year}-0001");

    Pest\Laravel\actingAs($user)
        ->postJson('/api/v1/documents', $payload('invoice'))
        ->assertCreated()
        ->assertJsonPath('number', "FAC-{$year}-0001");
});

it('refuse de purger sans --keep', function (): void {
    workspaceAccount();

    purge(['--force' => true])->assertFailed();

    // Rien n'a bougé : la garde s'exécute AVANT toute écriture.
    expect(DB::table('documents')->count())->toBeGreaterThan(0);
});

it('refuse de purger si le compte à préserver est introuvable', function (): void {
    workspaceAccount();

    // Le cas de l'e-mail mal orthographié. Sans ce refus, la purge partirait
    // avec une garde qui ne garde rien — et personne ne s'en apercevrait.
    purge(['--keep' => 'faute-de-frappe@exemple.test', '--force' => true])
        ->assertFailed();

    expect(DB::table('documents')->count())->toBeGreaterThan(0);
});

it('n’écrit rien en simulation', function (): void {
    [$user] = workspaceAccount();

    $before = DB::table('documents')->count();

    purge(['--keep' => $user->email, '--dry-run' => true])
        ->assertSuccessful();

    expect(DB::table('documents')->count())->toBe($before);
    expect(Sequence::query()->where('next_number', '>', 1)->exists())->toBeTrue();
});

it('supprime un devis converti et sa facture malgré leur parenté', function (): void {
    [$user] = workspaceAccount();

    $quote = Document::query()->where('type', 'quote')->whereNotNull('number')->firstOrFail();

    Pest\Laravel\actingAs($user)
        ->postJson("/api/v1/documents/{$quote->id}/convert")
        ->assertCreated();

    // `parent_document_id` est en RESTRICT : PostgreSQL vérifie la contrainte
    // ligne à ligne, et un `DELETE` global échouerait sans le dénouage
    // préalable. Le cas est le plus courant qui soit — toute base d'essai en
    // contient — et il ne se voit qu'ici.
    purge(['--keep' => $user->email, '--force' => true])
        ->assertSuccessful();

    expect(DB::table('documents')->count())->toBe(0);
});
