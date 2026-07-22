<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Documents\Requests\DocumentStatusRequest;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;

/** Devis accepté ou refusé, facture réglée ou échue. */
final class DocumentStatusController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentWriteService $writes,
    ) {}

    public function __invoke(DocumentStatusRequest $request, string $document): JsonResponse
    {
        $model = $this->documents->findForCompany($document);
        $target = DocumentStatus::from($request->string('status')->toString());

        return response()->json(
            (new DocumentResource($this->writes->changeStatus($model, $target)))->resolve(),
        );
    }
}
