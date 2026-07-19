<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Exceptions;

use RuntimeException;

/**
 * Levée quand une opération tenant est tentée sans société active.
 * C'est toujours un bug d'orchestration (middleware ou job mal configuré),
 * jamais une situation utilisateur : on échoue fort plutôt que de laisser
 * passer une requête non scopée.
 */
final class TenantContextMissing extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Aucune société active : le contexte tenant doit être résolu avant toute opération métier.');
    }
}
