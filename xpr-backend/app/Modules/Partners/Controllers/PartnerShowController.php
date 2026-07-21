<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Resources\PartnerResource;
use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;

final class PartnerShowController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(string $partner): JsonResponse
    {
        return response()->json(
            (new PartnerResource($this->partners->findForCompany($partner)))->resolve(),
        );
    }
}
