<?php

declare(strict_types=1);

namespace App\Modules\Cash\Console;

use App\Modules\Cash\Services\PaymentCashMirror;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;

/**
 * Aligne le journal de caisse sur les règlements de factures.
 *
 * Deux usages :
 *
 *  1. la REPRISE, à lancer une fois après le passage au miroir (2026-08-25) :
 *     les règlements antérieurs n'ont pas de mouvement, et sans eux la caisse
 *     afficherait 0,00 MAD là où elle affichait le bon total la veille — la
 *     fusion en lecture qui les rendait visibles a été retirée ;
 *  2. la RÉPARATION, à tout moment. C'est la contrepartie de la duplication :
 *     une écriture qui contourne `PaymentWriteService` — import, script,
 *     requête SQL directe — laisse la caisse en retard sans que rien ne le
 *     signale. Cette commande remet les deux tables d'accord.
 *
 * Idempotente par construction : `PaymentCashMirror::rebuild()` ALIGNE un état,
 * il ne l'incrémente pas. La relancer deux fois de suite ne produit aucun
 * doublon — l'index `cash_movements_payment_unique` le garantit d'ailleurs en
 * base.
 */
final class BackfillCashMirrorCommand extends Command
{
    protected $signature = 'xpr:backfill-cash-mirror';

    protected $description = 'Recrée les mouvements de caisse manquants pour les règlements de factures';

    public function handle(TenantContext $tenant, PaymentCashMirror $mirror): int
    {
        // Chaque société sous SON contexte : sans lui, le global scope filtre
        // sur une société nulle et la commande réussirait sans rien aligner
        // (§15).
        $companies = Company::query()->orderBy('legal_name')->get();
        $rows = [];

        foreach ($companies as $company) {
            $result = $tenant->runForCompany(
                $company->id,
                static fn (): array => $mirror->rebuild(),
            );

            $rows[] = [
                $company->legal_name,
                (string) $result['synced'],
                (string) $result['removed'],
            ];
        }

        $this->table(['Société', 'Règlements alignés', 'Miroirs orphelins retirés'], $rows);

        return self::SUCCESS;
    }
}
