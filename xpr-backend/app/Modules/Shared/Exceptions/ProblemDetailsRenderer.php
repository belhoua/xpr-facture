<?php

declare(strict_types=1);

namespace App\Modules\Shared\Exceptions;

use App\Modules\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use RuntimeException;
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
        } elseif ($e instanceof TenantContextMissing) {
            // Compte authentifié, mais rattaché à AUCUNE société : les écrans
            // métier n'ont pas de périmètre où travailler. C'est un état de
            // compte, pas une panne — le laisser remonter en 500 affichait
            // « Une erreur est survenue » sur tous les écrans à la fois, sans
            // jamais dire que la seule chose qui manquait était une société.
            //
            // 409 et non 403 : le front déconnecte sur 401/403, et un 403 ici
            // renverrait vers /login un utilisateur dont les identifiants sont
            // parfaitement valides — une boucle de reconnexion sans issue.
            // 409 dit exactement ce qui se passe : la requête est légitime,
            // l'état du compte l'empêche.
            $status = 409;
            $title = __('No active company');
            $detail = __('This account is not attached to any company yet.');
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $e->getMessage() !== '' ? $e->getMessage() : null;
            $headers = $e->getHeaders();
        } elseif (self::isMissingStatefulSession($e, $request)) {
            // Le statut reste 500, délibérément : l'origine de l'appel n'est pas
            // déclarée stateful, donc Sanctum n'a pas injecté StartSession. C'est
            // un défaut de CONFIGURATION du déploiement, que le client ne peut
            // corriger en reformulant sa requête — un 4xx lui ferait croire le
            // contraire. Seul le message change, et c'est tout l'enjeu : sans
            // cette branche, la réponse était « Internal server error » suivie,
            // au mieux, de « Session store not set on request. » — exact, et
            // parfaitement muet sur ce qu'il faut corriger.
            $status = 500;
            $title = __('Session authentication is not available for this origin');
            $detail = self::statelessOriginDetail($request);
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

    /**
     * L'appel vient-il d'une origine que Sanctum ne reconnaît pas comme
     * stateful ?
     *
     * Laravel ne dédie aucune classe à ce cas : `Request::session()` lève une
     * RuntimeException nue. On ne se contente donc PAS du message, qui peut
     * changer d'une version à l'autre — on le confirme en redemandant à Sanctum
     * lui-même si l'origine est stateful, avec la méthode qui a pris la
     * décision en amont. Les deux conditions réunies, le diagnostic est certain.
     *
     * Si le message évolue, on retombe simplement sur le 500 générique : cette
     * détection ne peut pas masquer une autre panne.
     */
    private static function isMissingStatefulSession(Throwable $e, Request $request): bool
    {
        return $e instanceof RuntimeException
            && str_contains($e->getMessage(), 'Session store not set on request')
            && ! EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    /**
     * Message de diagnostic. L'origine reçue et le nom de la variable à
     * corriger ne sortent qu'en mode debug : en production, nommer sa
     * configuration est une fuite d'implémentation, et l'exploitant a les logs.
     */
    private static function statelessOriginDetail(Request $request): string
    {
        if (! config('app.debug')) {
            return __('This origin is not allowed to use session authentication.');
        }

        // Même ordre de lecture que Sanctum, pour désigner l'en-tête qui a
        // réellement servi à la décision.
        $origin = $request->headers->get('referer')
            ?: $request->headers->get('origin');

        if ($origin === null) {
            return __('The request carried neither Referer nor Origin, so no session was started. Both are required for cookie-based authentication.');
        }

        return __('The origin :origin is absent from SANCTUM_STATEFUL_DOMAINS, so no session was started. Add it there (ports included) and clear the config cache.', ['origin' => $origin]);
    }
}
