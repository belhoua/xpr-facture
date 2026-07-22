<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Requests\DocumentIssueRequest;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Émission : le point où le document acquiert son numéro fiscal et devient
 * immuable. Endpoint distinct de la mise à jour, et permission distincte —
 * c'est un acte engageant, pas une sauvegarde.
 */
final class DocumentIssueController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentWriteService $writes,
    ) {}

    public function __invoke(DocumentIssueRequest $request, string $document): JsonResponse
    {
        $model = $this->documents->findForCompany($document);
        $issuedAt = $request->date('issuedAt');

        return response()->json(
            (new DocumentResource($this->writes->issue(
                $model,
                $issuedAt instanceof Carbon ? $issuedAt : null,
            )))->resolve(),
        );
    }
}
