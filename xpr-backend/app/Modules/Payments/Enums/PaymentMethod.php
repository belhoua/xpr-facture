<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/**
 * Modes de règlement. Valeurs miroir de `payments_method_check`.
 *
 * La liste est celle du terrain marocain, et non une traduction d'un catalogue
 * générique : le CHÈQUE y reste dominant, la LCN (lettre de change relevé) est
 * l'effet de commerce courant entre entreprises, et le VERSEMENT désigne
 * l'espèce déposée au guichet de la banque du fournisseur — distinct du
 * virement, qui part d'un compte.
 */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Cheque = 'cheque';
    case Transfer = 'transfer';
    case Card = 'card';
    case Lcn = 'lcn';
    case Deposit = 'deposit';

    /**
     * Le mode est-il un EFFET BANCAIRE, c'est-à-dire un titre qui circule ?
     *
     * Ces deux-là seuls portent un numéro, des dates de remise et de réception,
     * un statut et un scan : entre la remise du titre et l'encaissement
     * effectif s'écoulent des jours, et l'effet peut revenir impayé. Les autres
     * modes sont soldés à l'instant où ils sont saisis.
     *
     * Une méthode plutôt qu'un `match` disséminé : la règle est lue par la
     * validation, par l'écriture et par la contrainte CHECK qu'elle reflète.
     */
    public function isBankInstrument(): bool
    {
        return $this === self::Cheque || $this === self::Lcn;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
