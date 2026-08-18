<?php

declare(strict_types=1);

namespace App\Modules\Cash\Services;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Partners\Models\Partner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Écritures sur les mouvements de caisse. Contrairement aux factures, un
 * mouvement n'est pas un document fiscal immuable : il se crée, se corrige et
 * se supprime librement. Le `company_id` est posé par BelongsToCompany (§5),
 * jamais renseigné ici.
 *
 * Une seule règle y est tenue : **le tiers doit appartenir à la société
 * active**. La vérification passe par une requête scopée et non par une règle
 * `exists` de validation, qui interrogerait `partners` sans le global scope et
 * accepterait le tiers d'une autre société (§5.3).
 *
 * @phpstan-type MovementData array{
 *     partnerId?: ?string,
 *     occurredAt: string,
 *     label: string,
 *     method: string,
 *     registerName: string,
 *     amountCents: int,
 *     currency: string,
 * }
 */
final class CashMovementWriteService
{
    /** @param  MovementData  $data */
    public function create(array $data): CashMovement
    {
        return CashMovement::query()
            ->create($this->toColumns($data))
            ->load('partner');
    }

    /** @param  MovementData  $data */
    public function update(CashMovement $movement, array $data): CashMovement
    {
        $movement->update($this->toColumns($data));

        return $movement->load('partner');
    }

    public function delete(CashMovement $movement): void
    {
        $movement->delete();
    }

    /**
     * @param  MovementData  $data
     * @return array<string, mixed>
     */
    private function toColumns(array $data): array
    {
        return [
            'partner_id' => $this->resolvePartner($data['partnerId'] ?? null),
            'occurred_at' => $data['occurredAt'],
            'label' => $data['label'],
            'method' => $data['method'],
            'register_name' => $data['registerName'],
            'amount_cents' => $data['amountCents'],
            'currency' => strtoupper($data['currency']),
        ];
    }

    /**
     * @throws ConflictHttpException si le tiers n'appartient pas à la société
     *                               active — 409 et non 404 : le tiers existe
     *                               peut-être, mais pas ici.
     */
    private function resolvePartner(?string $partnerId): ?string
    {
        if ($partnerId === null || $partnerId === '') {
            return null;
        }

        $partner = Partner::query()->find($partnerId);

        if (! $partner instanceof Partner) {
            throw new ConflictHttpException(__('The selected client does not belong to this company.'));
        }

        return $partner->id;
    }
}
