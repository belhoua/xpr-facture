<?php

declare(strict_types=1);

namespace App\Modules\Partners\Enums;

/**
 * Sens de la relation commerciale. Valeurs miroir de `partners_type_check`.
 *
 * `Both` existe parce que le cas est courant : un imprimeur à qui l'on achète
 * des fournitures et à qui l'on facture des prestations. Le forcer à choisir
 * obligerait à saisir deux fiches, donc à maintenir deux ICE.
 */
enum PartnerType: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Both = 'both';

    /** Apparaît-il dans la liste des clients (ventes) ? */
    public function isClient(): bool
    {
        return $this === self::Client || $this === self::Both;
    }

    /** Apparaît-il dans la liste des fournisseurs (achats) ? */
    public function isSupplier(): bool
    {
        return $this === self::Supplier || $this === self::Both;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
