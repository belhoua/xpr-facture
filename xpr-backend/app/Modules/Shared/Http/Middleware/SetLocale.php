<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue des réponses API (messages de validation, erreurs) : préférence du
 * compte si connecté, sinon négociation Accept-Language (arbitrage Q4 :
 * détection navigateur par défaut). Appliqué à tout le groupe 'api'.
 */
final class SetLocale
{
    private const SUPPORTED = ['fr', 'ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        // ?? tolère nativement la chaîne d'accès sur null (pas besoin de ?->)
        $locale = $request->user()->locale
            ?? $request->getPreferredLanguage(self::SUPPORTED)
            ?? config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }
}
