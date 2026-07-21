<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Requests\PartnerStoreRequest;
use App\Modules\Partners\Resources\PartnerResource;
use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PartnerStoreController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(PartnerStoreRequest $request): JsonResponse
    {
        $partner = $this->partners->create($request->validated());

        return response()->json(
            (new PartnerResource($partner))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
