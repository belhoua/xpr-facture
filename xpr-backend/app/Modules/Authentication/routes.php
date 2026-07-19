<?php

declare(strict_types=1);

use App\Modules\Authentication\Controllers\EmailVerificationController;
use App\Modules\Authentication\Controllers\LoginController;
use App\Modules\Authentication\Controllers\LogoutController;
use App\Modules\Authentication\Controllers\MeController;
use App\Modules\Authentication\Controllers\PasswordResetController;
use App\Modules\Authentication\Controllers\RegisterController;
use Illuminate\Support\Facades\Route;

// Chargées par AuthenticationServiceProvider. Le groupe 'api' apporte
// statefulApi (sessions Sanctum) et SetLocale (cf. bootstrap/app.php).
Route::middleware('api')->prefix('api/v1/auth')->group(function (): void {
    Route::post('register', RegisterController::class)->middleware('throttle:register');
    Route::post('login', LoginController::class)->middleware('throttle:login');
    Route::post('forgot-password', [PasswordResetController::class, 'sendLink'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:forgot-password');

    // Lien cliqué depuis l'e-mail : 'signed' garantit l'URL, pas de session requise
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
        Route::post('logout', LogoutController::class);
        Route::get('me', MeController::class);
        Route::post('email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:6,1');
    });
});
