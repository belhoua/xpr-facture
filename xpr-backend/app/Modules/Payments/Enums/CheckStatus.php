<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Sort d'un effet bancaire remis (chèque, LCN). Miroir de
 * `payments_check_status_check`.
 *
 * Ce statut ne dit RIEN du montant encaissé : un chèque rejeté reste un
 * règlement enregistré tant qu'il n'est pas supprimé, et son montant compte
 * toujours dans le cumul de la facture. Le lier au calcul reviendrait à décider
 * qu'un impayé efface la dette, ce qu'aucune comptabilité n'admet — la
 * régularisation se fait en retirant le règlement, geste tracé et délibéré.
 */
enum CheckStatus: string
{
    /** Remis, pas encore crédité. L'état par défaut d'un titre qui circule. */
    case Pending = 'pending';

    /** Crédité sur le compte. */
    case Cashed = 'cashed';

    /** Revenu impayé. */
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
