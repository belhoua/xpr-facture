<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;

/**
 * Écritures sur les mouvements de caisse. Contrairement aux factures, un
 * mouvement n'est pas un document fiscal immuable : il se crée, se corrige et
 * se supprime librement. Le `company_id` est posé par BelongsToCompany (§5),
 * jamais renseigné ici.
 */
final class CashMovementWriteService
{
    /**
     * @param  array{occurredAt: string, label: string, method: string, registerName: string, amountCents: int, currency: string}  $data
     */
    public function create(array $data): CashMovement
    {
        return CashMovement::query()->create($this->toColumns($data));
    }

    /**
     * @param  array{occurredAt: string, label: string, method: string, registerName: string, amountCents: int, currency: string}  $data
     */
    public function update(CashMovement $movement, array $data): CashMovement
    {
        $movement->update($this->toColumns($data));

        return $movement;
    }

    public function delete(CashMovement $movement): void
    {
        $movement->delete();
    }

    /**
     * @param  array{occurredAt: string, label: string, method: string, registerName: string, amountCents: int, currency: string}  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        return [
            'occurred_at' => $data['occurredAt'],
            'label' => $data['label'],
            'method' => $data['method'],
            'register_name' => $data['registerName'],
            'amount_cents' => $data['amountCents'],
            'currency' => strtoupper($data['currency']),
        ];
    }
}
