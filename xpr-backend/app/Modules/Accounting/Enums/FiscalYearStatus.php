<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Valeurs miroir de la contrainte CHECK `fiscal_years_status_check`.
 * Toute valeur ajoutée ici doit l'être aussi en base, par migration.
 */
enum FiscalYearStatus: string
{
    /** Exercice courant : on peut y émettre des documents. */
    case Open = 'open';

    /** Clôture en cours : plus d'émission, les corrections restent possibles. */
    case Closing = 'closing';

    /** Exercice clos : figé, aucune écriture. */
    case Closed = 'closed';
}
