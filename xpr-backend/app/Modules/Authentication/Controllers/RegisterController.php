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
        $account = $registration->register(RegisterData::fromRequest($request));

        Auth::guard('web')->login($account['user']);
        $request->session()->regenerate();

        $context->authenticateUser($account['user']->id);
        $context->activateCompany($account['company']->id);

        return response()->json([
            'user' => new UserResource($account['user']),
            'company' => new CompanyResource($account['company']),
        ], 201);
    }
}
