<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Requests\ProjectStoreRequest;
use App\Modules\Projects\Resources\ProjectResource;
use App\Modules\Projects\Services\ProjectWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProjectStoreController
{
    public function __construct(private readonly ProjectWriteService $writes) {}

    public function __invoke(ProjectStoreRequest $request): JsonResponse
    {
        return response()->json(
            (new ProjectResource($this->writes->create($request->validated())))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
