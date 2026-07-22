<?php

declare(strict_types=1);

namespace App\Modules\Documents\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Tentative de modifier ou de supprimer un document déjà validé.
 *
 * C'est l'immuabilité du §3, appliquée côté serveur : l'interface masque déjà
 * les actions, mais l'API reste l'autorité — une requête forgée ne doit pas
 * passer (§10). La correction d'un document émis se fait par AVOIR, jamais par
 * une édition ni un DELETE.
 */
final class DocumentNotEditable extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct(__('This document is issued and can no longer be modified or deleted.'));
    }
}
