<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Suppression réservée aux BROUILLONS — le service répond 409 sinon (§3). */
final class DocumentDeleteController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentWriteService $writes,
    ) {}

    public function __invoke(string $document): JsonResponse
    {
        $this->writes->delete($this->documents->findForCompany($document));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
