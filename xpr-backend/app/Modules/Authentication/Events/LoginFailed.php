<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tentative de connexion échouée. On ne stocke que l'e-mail TENTÉ (pas de
 * lien vers un compte : il n'existe peut-être pas) — utile à l'audit pour
 * repérer une attaque par force brute distribuée.
 */
final class LoginFailed
{
    use Dispatchable;

    public function __construct(
        public readonly string $email,
        public readonly ?string $ipAddress,
    ) {}
}
