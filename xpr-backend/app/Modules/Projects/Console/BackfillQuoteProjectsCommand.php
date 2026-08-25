<?php

declare(strict_types=1);

namespace App\Modules\Projects\Console;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Models\Document;
use App\Modules\Projects\Services\ProjectWriteService;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ouvre rétroactivement les chantiers des devis antérieurs à la règle du
 * 2026-08-25 (`DocumentWriteService::withAutoProject()`).
 *
 * Sans elle, seuls les devis enregistrés APRÈS la levée porteraient un projet :
 * l'écran « Avancement de projet » resterait vide sur un portefeuille existant,
 * ce qui est précisément le symptôme qu'on vient de corriger.
 *
 * ── Écriture DIRECTE, et pourquoi c'est correct ici ───────────────────────
 *
 * Le rattachement est posé sans passer par `DocumentWriteService::update()`,
 * qui refuserait un devis converti, refusé ou annulé (§3). Une reprise de
 * données n'est pas une modification de pièce : elle ne touche ni au montant,
 * ni au numéro, ni à l'état — seulement à une colonne de RATTACHEMENT
 * introduite après coup. C'est le régime d'une migration de données, et c'est
 * pourquoi la commande n'écrit que `project_id`.
 *
 * ── La facture suit son devis ─────────────────────────────────────────────
 *
 * Un devis converti a produit une facture qui, elle non plus, ne porte aucun
 * projet — elle en a hérité à une époque où il n'y en avait pas. Elle est
 * rattachée au même chantier : sans cela, l'écran « situations par client »
 * filtré sur ce chantier montrerait la proposition sans jamais montrer ce qui a
 * été facturé.
 *
 * Idempotente : relancée, elle ne touche plus rien — les devis déjà rattachés
 * sont hors de sa requête, et `openFor()` réutilise le chantier existant.
 */
final class BackfillQuoteProjectsCommand extends Command
{
    protected $signature = 'xpr:backfill-quote-projects
        {--dry-run : Liste ce qui serait rattaché, sans rien écrire}';

    protected $description = 'Ouvre les chantiers manquants des devis déjà enregistrés';

    public function handle(TenantContext $tenant, ProjectWriteService $projects): int
    {
        // La commande n'a pas d'utilisateur authentifié : sans contexte tenant,
        // le global scope filtre sur une société nulle et ne verrait AUCUN
        // devis — elle réussirait en ne faisant rien (§15). Chaque société est
        // donc traitée sous son propre contexte.
        $companies = Company::query()->orderBy('legal_name')->get();

        /** @var list<array<int, string>> $rows */
        $rows = [];

        foreach ($companies as $company) {
            $tenant->runForCompany($company->id, function () use ($company, $projects, &$rows): void {
                $quotes = Document::query()
                    ->where('type', DocumentType::Quote->value)
                    ->whereNull('project_id')
                    ->whereNotNull('partner_id')
                    ->whereNotNull('subject')
                    ->where('subject', '!=', '')
                    ->get();

                foreach ($quotes as $quote) {
                    $rows[] = $this->attach($company, $quote, $projects);
                }
            });
        }

        if ($rows === []) {
            $this->info('Aucun devis à rattacher : tous portent déjà un chantier.');

            return self::SUCCESS;
        }

        $this->table(['Société', 'Devis', 'Objet', 'Chantier', 'Pièces liées'], $rows);

        if ($this->option('dry-run') === true) {
            $this->info(count($rows).' devis seraient rattachés. Rien n\'a été écrit.');
        }

        return self::SUCCESS;
    }

    /**
     * Rattache un devis — et la facture qui en découle — à son chantier.
     *
     * @return array<int, string>
     */
    private function attach(Company $company, Document $quote, ProjectWriteService $projects): array
    {
        $subject = trim((string) $quote->subject);
        $partnerId = (string) $quote->partner_id;

        if ($this->option('dry-run') === true) {
            return [
                $company->legal_name,
                $quote->number ?? '(brouillon)',
                $subject,
                '→ à ouvrir',
                (string) $quote->children()->whereNull('project_id')->count(),
            ];
        }

        // Une transaction par devis : un rattachement raté ne doit pas défaire
        // ceux qui ont abouti, et la reprise se relance sans état à nettoyer.
        return DB::transaction(function () use ($company, $quote, $projects, $subject, $partnerId): array {
            $project = $projects->openFor($partnerId, $subject);

            // `forceFill` + `save` et non `update()` du service : voir le
            // docblock de classe — c'est une reprise de données, pas une
            // modification de pièce.
            $quote->forceFill(['project_id' => $project->id])->save();

            // Les descendants du devis : la facture née de sa conversion, qui
            // n'a pu hériter d'un projet qui n'existait pas encore. Seuls ceux
            // qui n'en portent aucun sont touchés.
            $linked = $quote->children()
                ->whereNull('project_id')
                ->update(['project_id' => $project->id]);

            return [
                $company->legal_name,
                $quote->number ?? '(brouillon)',
                $subject,
                $project->title,
                (string) $linked,
            ];
        });
    }
}
