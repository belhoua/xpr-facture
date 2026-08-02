<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Enums\LegalForm;
use App\Modules\Tenancy\Services\CompanyProvisioning;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * `company_user` porte une policy RLS dont le WITH CHECK exige app.company_id
 * et ne tolère pas NULL. L'attach du provisioning s'exécutait avant que le
 * contexte ne soit posé — il ne l'était que plus bas, par
 * accounting->initialize() : PostgreSQL refusait la ligne, avortait la
 * transaction, et toute l'inscription remontait en 25P02.
 *
 * Ce test ne peut PAS observer le refus lui-même : phpunit.xml se connecte en
 * xpr_owner, SUPERUSER, donc exempt de FORCE ROW LEVEL SECURITY (cf. CLAUDE.md
 * §15, reliquat P0-09). Il verrouille donc l'invariant qui, lui, est
 * observable : le contexte est posé AVANT la première écriture sous RLS.
 * Tant que la suite ne tourne pas sous un rôle non-superuser, c'est la seule
 * garde possible — et elle aurait suffi à attraper le défaut.
 */
it('pose le contexte tenant avant la première écriture sous RLS', function (): void {
    // Le jeu de démonstration n'ajoute que du bruit : l'invariant se joue sur
    // les toutes premières requêtes du provisioning.
    config(['xpr.demo_data_on_signup' => false]);

    $user = User::factory()->create();

    $executed = [];

    DB::listen(function (QueryExecuted $query) use (&$executed): void {
        $executed[] = $query->sql;
    });

    app(CompanyProvisioning::class)->createFirstCompanyFor(
        $user,
        'Atlas Conseil SARL',
        LegalForm::from('sarl'),
    );

    $contextPosedAt = null;
    $membershipWrittenAt = null;

    foreach ($executed as $index => $sql) {
        if ($contextPosedAt === null && str_contains($sql, 'set_config')) {
            $contextPosedAt = $index;
        }

        if ($membershipWrittenAt === null && str_contains($sql, 'company_user')) {
            $membershipWrittenAt = $index;
        }
    }

    expect($contextPosedAt)->not->toBeNull('aucun set_config exécuté')
        ->and($membershipWrittenAt)->not->toBeNull('aucune écriture dans company_user')
        ->and($contextPosedAt)->toBeLessThan($membershipWrittenAt);
});
