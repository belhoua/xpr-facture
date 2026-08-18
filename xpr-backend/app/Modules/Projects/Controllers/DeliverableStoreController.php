<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Requests\DeliverableStoreRequest;
use App\Modules\Projects\Resources\DeliverableResource;
use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\ProjectWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ajout d'un livrable remis. Le projet vient du CHEMIN : il est résolu sous le
 * scope tenant avant l'écriture, là où un `projectId` posté permettrait
 * d'accrocher la remise au projet d'une autre société (§5.3).
 */
final class DeliverableStoreController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectWriteService $writes,
    ) {}

    public function __invoke(DeliverableStoreRequest $request, string $project): JsonResponse
    {
        $model = $this->projects->findForCompany($project);

        return response()->json(
            (new DeliverableResource(
                $this->writes->addDeliverable($model, $request->validated()),
            ))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
