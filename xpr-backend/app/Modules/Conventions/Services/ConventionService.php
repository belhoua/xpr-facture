<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Services;

use App\Modules\Conventions\Enums\ConventionStatus;
use App\Modules\Conventions\Models\Convention;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Lecture et écriture des contrats de convention.
 *
 * Le `company_id` n'est jamais manipulé ici : `BelongsToCompany` le renseigne à
 * la création et cloisonne toutes les requêtes (§5).
 */
final class ConventionService
{
    /**
     * @param  array{search?: ?string, status?: ?string, perPage?: ?int}  $filters
     * @return LengthAwarePaginator<int, Convention>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Convention::query()->with('deposits');

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($status = $filters['status'] ?? null) !== null) {
            $query->where('status', ConventionStatus::from($status)->value);
        }

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $query
            // Les plus récentes d'abord, et `created_at` en second : une
            // convention sans date d'établissement (rédigée, pas encore datée)
            // doit rester en tête plutôt que de tomber au fond de la liste.
            ->orderByDesc('issued_at')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Résout une convention de la SOCIÉTÉ ACTIVE.
     *
     * Volontairement pas un binding implicite de route : SubstituteBindings
     * s'exécute avant le middleware `tenant`, donc avant que le scope ne soit
     * armé — la résolution se ferait sans filtre de société
     * (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
     */
    public function findForCompany(string $id): Convention
    {
        $convention = Convention::query()->with(['deposits', 'sourceDocument'])->find($id);

        if (! $convention instanceof Convention) {
            throw (new ModelNotFoundException)->setModel(Convention::class, [$id]);
        }

        return $convention;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Convention
    {
        $convention = Convention::query()->create($this->toColumns($data));

        // Recharge les colonnes dont la valeur par défaut est posée par
        // PostgreSQL (status, currency, lots, pourcentages) : l'instance issue
        // de create() ne les connaît pas et la réponse JSON les rendrait nulles.
        return $convention->refresh();
    }

    /**
     * Correction d'une convention, y compris SIGNÉE.
     *
     * Ce n'est pas une entorse au §3 : le gel de l'immuabilité vise les pièces
     * opposables à l'administration fiscale, numérotées par `sequences`. Une
     * convention est un contrat de droit privé entre deux parties — corriger un
     * titre foncier mal saisi avant le dépôt du dossier est le cours normal des
     * choses, et l'interdire obligerait à refaire le contrat pour une coquille.
     *
     * Seul l'état ANNULÉ ferme la porte : rouvrir une convention abandonnée
     * effacerait la trace de l'abandon.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Convention $convention, array $data): Convention
    {
        $this->assertEditable($convention);

        $convention->update($this->toColumns($data));

        return $convention->refresh();
    }

    /**
     * Suppression (soft delete) : la ligne reste en base avec son `deleted_at`.
     *
     * Ouverte à tous les états sauf `signed` — un contrat signé engage les deux
     * parties et se retire par une ANNULATION, qui laisse l'objet visible et
     * daté. Le supprimer effacerait de l'écran un engagement qui, lui, existe
     * toujours sur le papier signé par le client.
     */
    public function delete(Convention $convention): void
    {
        if ($convention->status === ConventionStatus::Signed) {
            throw new ConflictHttpException(__('A signed convention cannot be deleted: cancel it instead.'));
        }

        $convention->delete();
    }

    /** Une convention annulée ne se modifie plus. */
    private function assertEditable(Convention $convention): void
    {
        if ($convention->status->isTerminal()) {
            throw new ConflictHttpException(__('A cancelled convention can no longer be modified.'));
        }
    }

    /**
     * Traduit le payload camelCase de l'API vers les colonnes snake_case.
     * Les clés absentes ne sont pas écrites : une mise à jour partielle ne doit
     * pas réinitialiser les champs non transmis.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        $map = [
            'partnerId' => 'partner_id',
            'sourceDocumentId' => 'source_document_id',
            'dossierNumber' => 'dossier_number',
            'status' => 'status',
            'issueCity' => 'issue_city',
            'issuedAt' => 'issued_at',
            'ownerName' => 'owner_name',
            'ownerIce' => 'owner_ice',
            'ownerRc' => 'owner_rc',
            'ownerAddress' => 'owner_address',
            'projectDescription' => 'project_description',
            'projectAddress' => 'project_address',
            'projectTitleDeed' => 'project_title_deed',
            'lots' => 'lots',
            'executionDelay' => 'execution_delay',
            'totalCents' => 'total_cents',
            'currency' => 'currency',
            'advancePercent' => 'advance_percent',
            'visaPercent' => 'visa_percent',
            'completionPercent' => 'completion_percent',
            'notes' => 'notes',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        return $columns;
    }
}
