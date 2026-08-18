<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\ProjectWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ProjectDeleteController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectWriteService $writes,
    ) {}

    public function __invoke(string $project): JsonResponse
    {
        $this->writes->delete($this->projects->findForCompany($project));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
