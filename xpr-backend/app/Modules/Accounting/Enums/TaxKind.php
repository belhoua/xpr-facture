<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Nature fiscale d'un taux. Valeurs miroir de `tax_rates_kind_check`.
 *
 * La distinction n'est pas cosmétique : « exonéré » et « hors champ » valent
 * tous deux 0 % à la facture, mais ne se déclarent pas de la même façon et
 * n'imposent pas les mêmes mentions légales sur le document.
 */
enum TaxKind: string
{
    /** Taux ordinaire : 0, 7, 10, 14 ou 20 %. */
    case Standard = 'standard';

    /** Opération dans le champ de la TVA mais exonérée (art. 91 CGI). */
    case Exonere = 'exonere';

    /** Opération hors du champ d'application de la TVA. */
    case HorsChamp = 'hors_champ';
}
