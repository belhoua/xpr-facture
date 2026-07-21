<?php

use App\Modules\Shared\Exceptions\ProblemDetailsRenderer;
use App\Modules\Shared\Http\Middleware\SetLocale;
use App\Modules\Tenancy\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sessions cookie Sanctum pour le SPA (domaines déclarés dans
        // SANCTUM_STATEFUL_DOMAINS) — l'API reste stateless pour le reste.
        $middleware->statefulApi();

        // Langue des réponses (compte connecté, sinon Accept-Language)
        $middleware->api(append: [SetLocale::class]);

        // 'tenant' se place après auth:sanctum sur toute route métier :
        // il résout la société active et arme le scope Eloquent + la RLS.
        $middleware->alias([
            'tenant' => SetTenantContext::class,
        ]);

        // Backend d'API pur : aucune page de login à servir. Sans ceci, une
        // requête non authentifiée dépourvue d'`Accept: application/json`
        // fait chercher à Laravel la route nommée `login` — absente — et
        // renvoie « Route [login] not defined » en 500 au lieu d'un 401.
        // Retourner null laisse remonter l'AuthenticationException, que
        // ProblemDetailsRenderer sérialise en 401 RFC 9457.
        $middleware->redirectGuestsTo(fn (): ?string => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toutes les erreurs API sortent en RFC 9457 (P0-14)
        $exceptions->render(
            fn (Throwable $e, Request $request) => ProblemDetailsRenderer::render($e, $request),
        );
    })->create();
