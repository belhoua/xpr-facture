<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\Events\UserLoggedOut;
use App\Modules\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * US-4 : la session est invalidée CÔTÉ SERVEUR (pas un simple oubli de cookie
 * côté client) et le token CSRF régénéré.
 */
final class LogoutController
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        UserLoggedOut::dispatch($user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
