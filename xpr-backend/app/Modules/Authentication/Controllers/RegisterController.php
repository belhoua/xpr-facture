<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\DTO\RegisterData;
use App\Modules\Authentication\Requests\RegisterRequest;
use App\Modules\Authentication\Resources\UserResource;
use App\Modules\Authentication\Services\RegistrationService;
use App\Modules\Tenancy\Resources\CompanyResource;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * US-1 : inscription en une étape. Orchestration pure — la transaction vit
 * dans RegistrationService. L'utilisateur ressort connecté (cookie de
 * session) avec sa société active : aucun second appel nécessaire.
 */
final class RegisterController
{
    public function __invoke(
        RegisterRequest $request,
        RegistrationService $registration,
        TenantContext $context,
    ): JsonResponse {
        // ------------------------------------------------------------------
        // INSTRUMENTATION TEMPORAIRE — À RETIRER DÈS LE DIAGNOSTIC ÉTABLI.
        //
        // Le déploiement Vercel renvoie un 500 dont la cause n'est pas
        // observable : les logs partent dans storage/logs/, redirigé vers /tmp,
        // qui meurt avec l'instance. Ce bloc écrit l'exception sur le canal de
        // log configuré ET la renvoie à l'appelant.
        //
        // Ce qu'il coûte, et pourquoi il ne peut pas rester :
        //  - getTraceAsString() rend les ARGUMENTS SCALAIRES des appels de la
        //    pile. Un mot de passe qui transite en string y figure en clair,
        //    dans une réponse HTTP publique et dans les journaux — contraire à
        //    CLAUDE.md §10 (« logs sans données personnelles en clair ») et à
        //    la loi 09-08.
        //  - la trace expose l'arborescence serveur et la structure applicative.
        //  - il court-circuite ProblemDetailsRenderer : la réponse n'est plus
        //    au format RFC 9457, donc toApiProblem() côté frontend ne sait plus
        //    la lire et l'écran d'inscription n'affichera aucun message.
        //
        // Angle mort à connaître : ce bloc n'attrape que ce qui est levé DANS
        // la méthode. Une panne survenant avant — middleware de session,
        // validation du FormRequest, boot de l'application, APP_KEY absente,
        // base injoignable — ne passera jamais ici. Si la réponse reste un 500
        // sans ce corps JSON, la cause est en amont du contrôleur.
        // ------------------------------------------------------------------
        try {
            $account = $registration->register(RegisterData::fromRequest($request));

            Auth::guard('web')->login($account['user']);
            $request->session()->regenerate();

            $context->authenticateUser($account['user']->id);
            $context->activateCompany($account['company']->id);

            return response()->json([
                'user' => new UserResource($account['user']),
                'company' => new CompanyResource($account['company']),
            ], 201);
        } catch (Throwable $e) {
            // Chaîne déroulée : une QueryException enveloppe la PDOException
            // qui porte le SQLSTATE réel. Le premier maillon est le plus
            // superficiel, le dernier est la panne.
            $chain = [];

            for ($link = $e; $link !== null; $link = $link->getPrevious()) {
                $chain[] = [
                    'exception' => $link::class,
                    'message' => $link->getMessage(),
                    'origin' => $link->getFile().':'.$link->getLine(),
                ];
            }

            Log::error('Échec de l\'inscription', [
                'chain' => $chain,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
                'chain' => $chain,
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }
}
