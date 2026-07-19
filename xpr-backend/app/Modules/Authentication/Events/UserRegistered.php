<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Events;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;

/** Émis après commit de l'inscription. Consommateurs : audit (P0-12), onboarding (Phase 3). */
final class UserRegistered
{
    use Dispatchable;

    public function __construct(
        public readonly User $user,
        public readonly Company $company,
    ) {}
}
