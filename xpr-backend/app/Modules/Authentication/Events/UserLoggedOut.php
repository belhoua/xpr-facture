<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Events;

use App\Modules\Authentication\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Déconnexion volontaire. Consommateur : audit. */
final class UserLoggedOut
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
