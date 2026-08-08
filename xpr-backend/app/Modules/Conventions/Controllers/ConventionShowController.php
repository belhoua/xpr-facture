<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Resources\ConventionResource;
use App\Modules\Conventions\Services\ConventionService;
use Illuminate\Http\JsonResponse;

final class ConventionShowController
{
    public function __construct(private readonly ConventionService $conventions) {}

    /** `$convention` est l'identifiant brut : la route n'utilise pas de binding (§15). */
    public function __invoke(string $convention): JsonResponse
    {
        return response()->json(
            (new ConventionResource($this->conventions->findForCompany($convention)))->resolve(),
        );
    }
}
