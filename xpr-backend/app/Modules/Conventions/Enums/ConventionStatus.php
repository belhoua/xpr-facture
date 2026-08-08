<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Enums;

/**
 * Cycle de vie d'un contrat de convention. Valeurs miroir de la contrainte
 * CHECK `conventions_status_check`.
 *
 * Volontairement court — quatre états, là où un document commercial en compte
 * dix. Une convention ne connaît pas de règlement (les honoraires se facturent
 * ailleurs, sur la pièce qu'elle a produite) ni d'échéance : elle est rédigée,
 * transmise, signée. Ou abandonnée.
 */
enum ConventionStatus: string
{
    /** En rédaction. Seul état où la convention se supprime sans trace. */
    case Draft = 'draft';

    /** Transmise au maître d'ouvrage, en attente de sa signature. */
    case Sent = 'sent';

    /** Signée par les deux parties : le contrat produit ses effets. */
    case Signed = 'signed';

    /** Abandonnée. État TERMINAL — on n'en revient pas. */
    case Cancelled = 'cancelled';

    /**
     * États depuis lesquels plus aucune transition n'est offerte.
     *
     * `signed` n'en fait PAS partie : une convention signée reste corrigible
     * (une coquille sur le titre foncier se rectifie avant le dépôt). C'est un
     * contrat entre deux parties, pas une pièce opposable à la DGI — le gel du
     * §3 ne la concerne donc pas, et l'y étendre par analogie obligerait à
     * refaire un contrat pour une faute de frappe.
     */
    public function isTerminal(): bool
    {
        return $this === self::Cancelled;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
