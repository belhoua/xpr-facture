<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Types de documents commerciaux. Valeurs miroir de la contrainte CHECK
 * `sequences_doc_type_check`, et futur discriminant de la table `documents`
 * (arbitrage du 2026-07-21 : une table unique, pas une table par type).
 *
 * Chaque type porte son format de numérotation par défaut : la séquence est
 * créée avec, et la société peut ensuite le personnaliser (§3).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case Proforma = 'proforma';
    case PurchaseOrder = 'purchase_order';
    case DeliveryNote = 'delivery_note';
    case ShippingSlip = 'shipping_slip';
    case CreditNote = 'credit_note';
    case PurchaseInvoice = 'purchase_invoice';

    /** Format par défaut, préfixes usuels au Maroc. */
    public function defaultFormat(): string
    {
        return match ($this) {
            self::Invoice => 'FAC-{YYYY}-{0000}',
            self::Quote => 'DEV-{YYYY}-{0000}',
            self::Proforma => 'PRO-{YYYY}-{0000}',
            self::PurchaseOrder => 'BC-{YYYY}-{0000}',
            self::DeliveryNote => 'BL-{YYYY}-{0000}',
            self::ShippingSlip => 'FE-{YYYY}-{0000}',
            self::CreditNote => 'AV-{YYYY}-{0000}',
            self::PurchaseInvoice => 'FA-{YYYY}-{0000}',
        };
    }

    /**
     * Types dont une société dispose dès son inscription. Les autres (achats,
     * expédition) sont créés à la demande, quand le module correspondant est
     * livré : une séquence jamais utilisée n'a pas à exister.
     *
     * @return list<self>
     */
    public static function provisionedAtSignup(): array
    {
        return [self::Invoice, self::Quote, self::CreditNote];
    }
}
