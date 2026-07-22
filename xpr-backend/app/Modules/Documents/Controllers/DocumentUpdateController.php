<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Requests\DocumentUpdateRequest;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;

/**
 * Le document est résolu par le service, donc APRÈS le middleware `tenant` :
 * un binding implicite le résoudrait hors scope (cf. RouteBindingScopeTest).
 */
final class DocumentUpdateController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentWriteService $writes,
    ) {}

    public function __invoke(DocumentUpdateRequest $request, string $document): JsonResponse
    {
        $model = $this->documents->findForCompany($document);

        return response()->json(
            (new DocumentResource($this->writes->update($model, $request->validated())))->resolve(),
        );
    }
}
