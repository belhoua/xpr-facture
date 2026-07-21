<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Requests\PartnerUpdateRequest;
use App\Modules\Partners\Resources\PartnerResource;
use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;

/**
 * Le tiers est résolu dans le service, donc APRÈS le middleware `tenant` :
 * un binding implicite le résoudrait hors scope (cf. RouteBindingScopeTest).
 */
final class PartnerUpdateController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(PartnerUpdateRequest $request, string $partner): JsonResponse
    {
        $model = $this->partners->findForCompany($partner);

        return response()->json(
            (new PartnerResource($this->partners->update($model, $request->validated())))->resolve(),
        );
    }
}
