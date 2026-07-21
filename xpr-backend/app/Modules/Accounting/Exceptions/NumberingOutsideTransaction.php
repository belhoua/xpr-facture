<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Exceptions;

use App\Modules\Accounting\Enums\DocumentType;
use RuntimeException;

/**
 * Erreur de PROGRAMMATION, pas d'utilisation : hors transaction, le verrou de
 * ligne ne tient pas et deux validations concurrentes liraient le même
 * compteur. On refuse d'attribuer un numéro plutôt que d'en émettre un
 * potentiellement dupliqué.
 */
final class NumberingOutsideTransaction extends RuntimeException
{
    public function __construct(DocumentType $type)
    {
        parent::__construct(sprintf(
            'Numérotation de %s demandée hors transaction : appelez DocumentNumberService::allocate() '
            .'dans la transaction qui valide le document.',
            $type->value,
        ));
    }
}
