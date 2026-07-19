<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Controllers;

use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Resources\UserResource;
use App\Modules\Tenancy\Resources\CompanyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * US-6 : bootstrap du frontend en un appel — qui suis-je, quelle société est
 * active, entre lesquelles puis-je basculer. Les invitations en attente
 * (joined_at NULL) n'apparaissent pas : on ne liste que les appartenances.
 */
final class MeController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $activeCompany = $user->resolveActiveCompany();
        $companies = $user->companies()->whereNotNull('joined_at')->get();

        return response()->json([
            'user' => new UserResource($user),
            'active_company' => $activeCompany !== null ? new CompanyResource($activeCompany) : null,
            'companies' => CompanyResource::collection($companies),
        ]);
    }
}
