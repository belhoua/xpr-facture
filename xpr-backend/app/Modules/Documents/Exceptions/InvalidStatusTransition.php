<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use App\Modules\Documents\Enums\DocumentStatus;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Transition d'état refusée par le diagramme du type de document.
 *
 * 409 : la requête est bien formée, c'est l'état COURANT du document qui la
 * rend impossible. Un 422 laisserait croire à une erreur de saisie.
 */
final class InvalidStatusTransition extends ConflictHttpException
{
    public function __construct(DocumentStatus $from, DocumentStatus $to)
    {
        parent::__construct(__('Cannot move a document from :from to :to.', [
            'from' => $from->value,
            'to' => $to->value,
        ]));
    }
}
