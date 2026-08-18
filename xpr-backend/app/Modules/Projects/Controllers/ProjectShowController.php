<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectService;
use Illuminate\Http\JsonResponse;

/** Fiche d'un projet : son avancement et ses livrables remis. */
final class ProjectShowController
{
    public function __construct(private readonly ProjectService $projects) {}

    public function __invoke(string $project): JsonResponse
    {
        return response()->json(
            (new ProjectResource($this->projects->findForCompany($project)))->resolve(),
        );
    }
}
