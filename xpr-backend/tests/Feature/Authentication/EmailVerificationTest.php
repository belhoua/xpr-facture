<?php

declare(strict_types=1);

use App\Modules\Authentication\Models\User;
use Illuminate\Support\Facades\URL;

use function Pest\Laravel\get;

it("vérifie l'e-mail via le lien signé et redirige vers le frontend", function (): void {
    $user = User::factory()->unverified()->create();

    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    get($link)->assertRedirect(config('app.frontend_url').'/email-verified');

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

it('rejette un lien dont la signature est altérée', function (): void {
    $user = User::factory()->unverified()->create();

    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);

    get($link.'tampered')->assertStatus(403);

    expect($user->fresh()?->email_verified_at)->toBeNull();
});
