<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Enums;

/**
 * Instruction d'un dépôt de dossier. Valeurs miroir de la contrainte CHECK
 * `file_deposits_status_check`.
 *
 * Ce sont les quatre états que l'organisme instructeur fait connaître, ni plus
 * ni moins : le dossier est au guichet, il est à l'étude, il passe, il ne passe
 * pas. L'application ne les DÉDUIT jamais — aucune donnée en base ne dit qu'une
 * commission a statué ; c'est une information qui arrive par courrier.
 */
enum DepositStatus: string
{
    /** Déposé au guichet, récépissé en main. */
    case Deposited = 'deposited';

    /** En cours d'instruction par l'organisme. */
    case InProgress = 'in_progress';

    /** Validé : le dossier est accepté. */
    case Validated = 'validated';

    /** Rejeté : un nouveau dépôt sera nécessaire. */
    case Rejected = 'rejected';

    /**
     * L'organisme a-t-il tranché ? C'est ce qui rend `decided_at` significatif :
     * une date de décision sur un dossier « en cours » n'aurait rien à dater.
     */
    public function isDecided(): bool
    {
        return $this === self::Validated || $this === self::Rejected;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
