<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vérification d'e-mail NON bloquante en Phase 0 (arbitrage Q1) : le lien
 * arrive par mail, la route est signée (inviolable sans la clé applicative),
 * et l'utilisateur est redirigé vers le frontend une fois vérifié.
 */
final class EmailVerificationController
{
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }

        // 202 même si déjà vérifié : rien à énumérer, rien à re-cliquer
        return response()->json(['message' => __('verification.sent')], 202);
    }

    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::findOrFail($id);

        // Le middleware 'signed' garantit l'intégrité de l'URL ; le hash lie
        // le lien à l'adresse e-mail au moment de l'envoi (si l'adresse a
        // changé entre-temps, le lien devient invalide).
        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away(config('app.frontend_url').'/email-verified');
    }
}
