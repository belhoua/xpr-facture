<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Tenancy\Resources\CompanyMemberResource;
use App\Modules\Tenancy\Services\CompanyMemberService;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CompanyUserListController
{
    public function __construct(
        private readonly CompanyMemberService $members,
        private readonly TenantContext $tenant,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->tenant->requireCompany();
        $members = $this->members->listMembers($company);

        return response()->json([
            'data' => $members
                ->map(fn (array $member): array => (new CompanyMemberResource(
                    $member['user'],
                    ['role' => $member['role'], 'state' => $member['state']],
                ))->resolve())
                ->values()
                ->all(),
        ]);
    }
}
