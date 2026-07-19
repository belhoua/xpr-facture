<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\Events\LoginFailed;
use App\Modules\Authentication\Events\UserLoggedIn;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Requests\LoginRequest;
use App\Modules\Authentication\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * US-2/US-3. La limitation de débit (5/min par e-mail+IP) est appliquée par
 * le middleware throttle:login AVANT d'arriver ici. Le message d'échec est
 * neutre : il ne révèle jamais si l'e-mail existe (anti-énumération).
 */
final class LoginController
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $remember = $request->boolean('remember'); // US-3 : 30 jours (cookie recaller)

        $authenticated = Auth::guard('web')->attempt(
            $request->only(['email', 'password']),
            $remember,
        );

        if (! $authenticated) {
            LoginFailed::dispatch($request->string('email')->value(), $request->ip());

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Anti-fixation : l'identifiant de session change à chaque connexion
        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();
        UserLoggedIn::dispatch($user, $request->ip());

        return response()->json(['user' => new UserResource($user)]);
    }
}
