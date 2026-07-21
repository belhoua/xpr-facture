<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Requests\InviteUserRequest;
use App\Modules\Tenancy\Resources\CompanyMemberResource;
use App\Modules\Tenancy\Services\CompanyMemberService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;

final class InviteUserController
{
    public function __construct(
        private readonly CompanyMemberService $members,
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(InviteUserRequest $request): JsonResponse
    {
        /** @var User $inviter */
        $inviter = $request->user();
        $company = $this->tenant->requireCompany();

        // Payload reconstruit champ par champ : `validated()` est typé
        // array<string, mixed> et ne satisfait pas le contrat du service.
        $role = (string) $request->validated('role');

        $user = $this->members->invite($company, $inviter, [
            'name' => (string) $request->validated('name'),
            'email' => (string) $request->validated('email'),
            'role' => $role,
        ]);

        return response()->json(
            (new CompanyMemberResource($user, ['role' => $role, 'state' => 'invited']))->resolve(),
            201,
        );
    }
}
