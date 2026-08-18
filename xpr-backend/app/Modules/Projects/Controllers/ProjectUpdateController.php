<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Requests\ProjectUpdateRequest;
use App\Modules\Projects\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\ProjectWriteService;
use Illuminate\Http\JsonResponse;

/**
 * Correction d'un projet ET mise à jour de son avancement : le même endpoint
 * sert les deux, le PATCH n'écrivant que les clés reçues. Un endpoint
 * `/progress` distinct aurait dédoublé la validation de deux champs qui se
 * modifient ensemble — on passe « achevé » et « 100 % » d'un même geste.
 */
final class ProjectUpdateController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectWriteService $writes,
    ) {}

    public function __invoke(ProjectUpdateRequest $request, string $project): JsonResponse
    {
        $model = $this->projects->findForCompany($project);

        return response()->json(
            (new ProjectResource($this->writes->update($model, $request->validated())))->resolve(),
        );
    }
}
