<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * runForCompany() restaure le contexte précédent quand son callback échoue.
 * Tant que cette restauration passait par une requête, elle détruisait la
 * cause de l'échec au lieu de la laisser remonter :
 *
 *  1. une requête échoue dans le callback — la panne réelle ;
 *  2. PostgreSQL avorte la transaction : tout ce qui suit est refusé avec
 *     25P02, « current transaction is aborted », jusqu'au ROLLBACK ;
 *  3. le finally exécute set_config() et récolte donc ce 25P02 ;
 *  4. en PHP, une exception levée dans un finally REMPLACE celle en cours de
 *     propagation — sans la chaîner en previous.
 *
 * L'appelant ne recevait que le symptôme du nettoyage. Vu en production sur
 * Neon, où l'inscription renvoyait 25P02 sans jamais nommer sa cause.
 */
it('laisse remonter la cause réelle même quand la transaction est avortée', function (): void {
    $company = Company::factory()->create();

    $run = fn (): mixed => app(TenantContext::class)->runForCompany(
        $company->id,
        function (): never {
            // Avorte la transaction ouverte par RefreshDatabase, exactement
            // comme le ferait une violation de contrainte ou une policy RLS.
            try {
                DB::statement('SELECT 1 FROM table_absente_du_schema');
            } catch (QueryException) {
                // Ignorée volontairement : seul l'état de la transaction compte.
            }

            throw new RuntimeException('la panne que le diagnostic doit voir');
        },
    );

    expect($run)->toThrow(RuntimeException::class, 'la panne que le diagnostic doit voir');
});

it('restaure le contexte précédent après un échec, sans requête', function (): void {
    $outer = Company::factory()->create();
    $inner = Company::factory()->create();

    $context = app(TenantContext::class);
    $context->activateCompany($outer->id);

    try {
        $context->runForCompany($inner->id, function (): never {
            throw new RuntimeException('échec');
        });
    } catch (RuntimeException) {
        // Attendu : c'est la restauration qui est sous test.
    }

    expect($context->currentId())->toBe($outer->id);
});
