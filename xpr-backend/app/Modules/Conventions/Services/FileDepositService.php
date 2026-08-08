<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Services;

use App\Modules\Conventions\Enums\DepositStatus;
use App\Modules\Conventions\Models\Convention;
use App\Modules\Conventions\Models\FileDeposit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Suivi des dépôts de dossier.
 *
 * Le `company_id` n'est jamais manipulé ici : `BelongsToCompany` le renseigne à
 * la création et cloisonne toutes les requêtes (§5).
 */
final class FileDepositService
{
    /**
     * @param  array{search?: ?string, status?: ?string, conventionId?: ?string, perPage?: ?int}  $filters
     * @return LengthAwarePaginator<int, FileDeposit>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = FileDeposit::query()->with('convention');

        if (($search = $filters['search'] ?? null) !== null && trim($search) !== '') {
            $query->search(trim($search));
        }

        if (($status = $filters['status'] ?? null) !== null) {
            $query->where('status', DepositStatus::from($status)->value);
        }

        if (($conventionId = $filters['conventionId'] ?? null) !== null && $conventionId !== '') {
            $query->where('convention_id', $conventionId);
        }

        $perPage = min(max($filters['perPage'] ?? 25, 1), 100);

        return $query
            ->orderByDesc('deposited_at')
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Résout un dépôt de la SOCIÉTÉ ACTIVE — même raison qu'ailleurs de ne pas
     * s'en remettre à un binding implicite de route (§15).
     */
    public function findForCompany(string $id): FileDeposit
    {
        $deposit = FileDeposit::query()->with('convention')->find($id);

        if (! $deposit instanceof FileDeposit) {
            throw (new ModelNotFoundException)->setModel(FileDeposit::class, [$id]);
        }

        return $deposit;
    }

    /**
     * Enregistre un dépôt sur une convention.
     *
     * La convention est passée en OBJET déjà résolu sous le scope tenant, et non
     * par son identifiant : c'est ce qui garantit qu'on n'attache pas un dépôt à
     * la convention d'une autre société — le `convention_id` d'un payload n'est
     * jamais digne de confiance (§5.3).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Convention $convention, array $data): FileDeposit
    {
        $columns = $this->toColumns($data);
        $columns['convention_id'] = $convention->id;

        $deposit = FileDeposit::query()->create($columns);

        // Le n° de dossier de la convention SUIT le premier dépôt : c'est le
        // guichet qui le délivre, et le saisir deux fois — une fois sur le
        // récépissé, une fois sur le contrat — finirait par produire deux
        // numéros pour un seul dossier. Les dépôts suivants ne le rejouent pas :
        // un second dépôt après rejet reçoit une nouvelle référence, mais le
        // dossier reste celui que le contrat cite.
        if ($convention->dossier_number === null) {
            $convention->dossier_number = $deposit->reference;
            $convention->save();
        }

        return $deposit->refresh()->load('convention');
    }

    /** @param  array<string, mixed>  $data */
    public function update(FileDeposit $deposit, array $data): FileDeposit
    {
        $deposit->update($this->toColumns($data));

        return $deposit->refresh()->load('convention');
    }

    /**
     * Soft delete : un dépôt erroné se retire de l'écran, sa ligne reste en base
     * avec son `deleted_at`. Le récépissé imprimé, lui, ne se reprend pas.
     */
    public function delete(FileDeposit $deposit): void
    {
        $deposit->delete();
    }

    /**
     * Traduit le payload camelCase vers les colonnes snake_case, en n'écrivant
     * que les clés présentes (mise à jour partielle).
     *
     * `decidedAt` est le seul champ que le service ARBITRE : une date de
     * décision sur un dossier encore au guichet ne date rien, et l'y laisser
     * afficherait « validé le … » sur un statut « déposé ». Le statut fait foi,
     * pas la date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        $map = [
            'reference' => 'reference',
            'depositedAt' => 'deposited_at',
            'organisation' => 'organisation',
            'status' => 'status',
            'decidedAt' => 'decided_at',
            'notes' => 'notes',
        ];

        $columns = [];

        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        $status = isset($columns['status']) ? DepositStatus::from((string) $columns['status']) : null;

        if ($status !== null && ! $status->isDecided()) {
            $columns['decided_at'] = null;
        }

        return $columns;
    }
}
