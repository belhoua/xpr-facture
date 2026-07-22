<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

/**
 * Nature d'un article du catalogue. Valeurs miroir de `products_type_check`.
 *
 * La distinction n'est pas décorative : seul un BIEN peut être suivi en stock
 * (contrainte `products_stock_goods_only_check`), et la nature de l'opération
 * conditionne la retenue à la source, qui ne s'applique au Maroc qu'aux
 * prestations de services (§3).
 */
enum ProductType: string
{
    /** Bien matériel, stockable. */
    case Good = 'good';

    /** Prestation de service, non stockable. */
    case Service = 'service';
}
