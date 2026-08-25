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
 *     charge?: ?string,
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
        $this->assertEditable($movement);

        $movement->update($this->toColumns($data));

        return $movement->load('partner');
    }

    /**
     * Un mouvement COPIÉ d'un règlement ne se retouche pas ici.
     *
     * Sa source de vérité est le règlement, dont la facture dérive `paid_cents`
     * et son statut. Corriger la copie ferait dire à la caisse autre chose
     * qu'à la facture — exactement la divergence que la duplication rend
     * possible et que rien d'autre ne rattraperait (cf. `PaymentCashMirror`).
     *
     * 409 et non 403 : le geste n'est pas interdit à cet utilisateur, il est
     * sans objet sur cette ligne. L'écran ne le propose d'ailleurs pas — le
     * champ `source` le lui dit — mais une route ouverte doit se défendre
     * seule.
     *
     * @throws ConflictHttpException
     */
    private function assertEditable(CashMovement $movement): void
    {
        if ($movement->isMirroredPayment()) {
            throw new ConflictHttpException(
                __('This entry comes from an invoice payment: correct it on the invoice.'),
            );
        }
    }

    public function delete(CashMovement $movement): void
    {
        // Même garde qu'à la correction : effacer la copie sans effacer le
        // règlement ferait disparaître de la caisse un encaissement que la
        // facture continue de compter.
        $this->assertEditable($movement);

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
            // La CHARGE ne vaut que pour une sortie : elle est vidée sur un
            // encaissement plutôt que refusée, comme les champs d'effet le sont
            // sur un règlement en espèces. Un formulaire dont on bascule le
            // sens après avoir saisi une nature ne doit pas laisser cette
            // nature derrière lui — elle classerait une entrée d'argent en
            // « Loyer ».
            'charge' => ($data['amountCents'] < 0)
                ? self::trimmedOrNull($data['charge'] ?? null)
                : null,
            'method' => $data['method'],
            'register_name' => $data['registerName'],
            'amount_cents' => $data['amountCents'],
            'currency' => strtoupper($data['currency']),
        ];
    }

    /** Une chaîne vide est une absence de saisie, pas une valeur. */
    private static function trimmedOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
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
