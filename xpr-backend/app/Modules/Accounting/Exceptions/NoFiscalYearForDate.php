<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Aucun exercice comptable ne couvre la date d'émission demandée.
 *
 * Situation utilisateur légitime, pas un bug : la société tente d'émettre sur
 * une date hors de ses exercices ouverts (année suivante pas encore créée,
 * antériorité sur un exercice jamais défini). D'où un 409 lisible plutôt qu'une
 * erreur serveur — l'action corrective est d'ouvrir l'exercice.
 *
 * ConflictHttpException : ProblemDetailsRenderer la sérialise en RFC 9457, et
 * le message passe par __() donc sort en FR ou en AR selon la locale.
 */
final class NoFiscalYearForDate extends ConflictHttpException
{
    public function __construct(Carbon $date)
    {
        parent::__construct(__('No fiscal year covers :date — open the matching fiscal year first.', [
            'date' => $date->toDateString(),
        ]));
    }
}
