<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Requests\ConventionUpdateRequest;
use App\Modules\Conventions\Resources\ConventionResource;
use App\Modules\Conventions\Services\ConventionService;
use Illuminate\Http\JsonResponse;

final class ConventionUpdateController
{
    public function __construct(private readonly ConventionService $conventions) {}

    public function __invoke(ConventionUpdateRequest $request, string $convention): JsonResponse
    {
        $updated = $this->conventions->update(
            $this->conventions->findForCompany($convention),
            $request->validated(),
        );

        return response()->json((new ConventionResource($updated))->resolve());
    }
}
