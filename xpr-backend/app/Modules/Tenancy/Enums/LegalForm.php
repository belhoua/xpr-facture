<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Formes juridiques marocaines (CLAUDE.md §3). Enum PHP typé, adossé à la
 * contrainte CHECK companies_legal_form_check en base : les deux listes
 * doivent évoluer ensemble.
 */
enum LegalForm: string
{
    case AutoEntrepreneur = 'auto_entrepreneur';
    case Sarl = 'sarl';
    case SarlAu = 'sarl_au';
    case Sa = 'sa';
    case Sas = 'sas';
    case Snc = 'snc';
    case Cooperative = 'cooperative';

    /**
     * Un auto-entrepreneur sous le régime forfaitaire ne facture pas de TVA :
     * le drapeau vat_exempt est activé d'office à la création de la société
     * (modifiable ensuite dans les paramètres si la situation change).
     */
    public function defaultVatExempt(): bool
    {
        return $this === self::AutoEntrepreneur;
    }
}
