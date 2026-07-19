<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\Requests\ForgotPasswordRequest;
use App\Modules\Authentication\Requests\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * US-5. sendLink répond TOUJOURS le même message, que le compte existe ou
 * non : la réponse ne doit pas permettre d'énumérer les clients.
 */
final class PasswordResetController
{
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        // Le résultat du broker est volontairement ignoré dans la réponse
        // (compte inexistant ou soft-deleted → même 200 neutre).
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only(['email', 'password', 'token']),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password, // hachage via cast 'hashed'
                    'remember_token' => Str::random(60), // invalide les sessions "rester connecté"
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            // Token invalide/expiré : erreur sur le champ token, pas d'info compte
            throw ValidationException::withMessages(['token' => __($status)]);
        }

        return response()->json(['message' => __($status)]);
    }
}
