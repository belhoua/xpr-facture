<?php

declare(strict_types=1);

namespace App\Modules\Partners\Enums;

/**
 * Sens de la relation commerciale. Valeurs miroir de `partners_type_check`.
 *
 * `Both` existe parce que le cas est courant : un imprimeur à qui l'on achète
 * des fournitures et à qui l'on facture des prestations. Le forcer à choisir
 * obligerait à saisir deux fiches, donc à maintenir deux ICE.
 *
 * `Intermediary` est d'une autre nature, et c'est délibéré : il ne désigne pas
 * un sens de facturation mais un RÔLE — apporteur d'affaires, courtier, celui
 * par qui le dossier arrive. Il n'ouvre donc aucun cycle commercial (décision
 * du 2026-08-17) : ni `isClient()`, ni `isSupplier()`, et aucun déroulant de
 * facturation ne le propose. Lui ouvrir l'un des deux plus tard restera
 * possible ; le refermer après coup laisserait des documents rattachés
 * derrière lui.
 */
enum PartnerType: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Both = 'both';
    case Intermediary = 'intermediary';

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
