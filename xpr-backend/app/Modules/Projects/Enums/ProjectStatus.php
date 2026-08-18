<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Avancement d'un projet. Valeurs miroir de la contrainte CHECK
 * `projects_status_check`.
 *
 * Ces quatre états sont SAISIS, jamais déduits — contrairement au statut de
 * règlement d'une facture, qui se calcule depuis les encaissements. Aucune
 * donnée en base ne dit qu'un chantier est achevé : c'est le chef de projet qui
 * l'affirme, et le pourcentage d'avancement ne suffit pas à trancher (un projet
 * à 100 % peut rester « en suivi » pendant la garantie).
 */
enum ProjectStatus: string
{
    /** En cours : le travail est engagé. */
    case InProgress = 'in_progress';

    /** Achevé : les livrables sont remis, la mission est close. */
    case Completed = 'completed';

    /**
     * En suivi : la mission est rendue mais le dossier reste ouvert — période
     * de garantie, réserves à lever, dossier en instruction chez un organisme.
     */
    case Monitoring = 'monitoring';

    /** Annulé : le projet ne se fera pas. */
    case Canceled = 'canceled';

    /**
     * L'état interdit-il de faire encore avancer le projet ?
     *
     * Un projet annulé est clos : lui pousser un pourcentage d'avancement
     * reviendrait à décrire un travail qui n'aura pas lieu. « Achevé » et
     * « en suivi » restent ouverts — on corrige un chiffre erroné après coup,
     * et on remet un livrable oublié pendant la garantie.
     */
    public function isTerminal(): bool
    {
        return $this === self::Canceled;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
