<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Requests\ConventionStoreRequest;
use App\Modules\Conventions\Resources\ConventionResource;
use App\Modules\Conventions\Services\ConventionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConventionStoreController
{
    public function __construct(private readonly ConventionService $conventions) {}

    public function __invoke(ConventionStoreRequest $request): JsonResponse
    {
        $convention = $this->conventions->create($request->validated());

        return response()->json(
            (new ConventionResource($convention))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
