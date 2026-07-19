<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * P0-14 : toutes les erreurs de l'API sortent au format RFC 9457
 * (application/problem+json) — c'est le contrat que consomme
 * lib/api/client.ts côté frontend. Retourne null hors API : le rendu
 * HTML par défaut de Laravel reprend la main.
 */
final class ProblemDetailsRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        /** @var array<string, array<int, string>>|null $errors */
        $errors = null;
        $headers = [];
        $detail = null;

        if ($e instanceof ValidationException) {
            $status = $e->status;
            $title = __('Invalid data submitted');
            $errors = $e->errors();
        } elseif ($e instanceof AuthenticationException) {
            $status = 401;
            $title = __('Unauthenticated');
        } elseif ($e instanceof ModelNotFoundException) {
            $status = 404;
            $title = __('Resource not found');
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : null;
            $headers = $e->getHeaders();
        } else {
            $status = 500;
            $title = __('Internal server error');
            // Jamais de message interne en production : fuite d'implémentation
            $detail = config('app.debug') ? $e->getMessage() : null;
        }

        $problem = array_filter([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'errors' => $errors,
        ], static fn (mixed $value): bool => $value !== null);

        return new JsonResponse(
            $problem,
            $status,
            [...$headers, 'Content-Type' => 'application/problem+json'],
        );
    }
}
