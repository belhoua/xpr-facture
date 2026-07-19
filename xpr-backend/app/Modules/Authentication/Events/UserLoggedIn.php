<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Events;

use App\Modules\Authentication\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Connexion réussie. Consommateurs : audit, détection d'activité suspecte (backlog). */
final class UserLoggedIn
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly ?string $ipAddress,
    ) {}
}
