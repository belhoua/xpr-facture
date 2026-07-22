<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;

/**
 * Annulation : seul changement d'état permis sur un document émis (§3).
 * Ne supprime rien — la trace reste, avec le statut `cancelled`.
 */
final class DocumentCancelController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentWriteService $writes,
    ) {}

    public function __invoke(string $document): JsonResponse
    {
        $model = $this->documents->findForCompany($document);

        return response()->json(
            (new DocumentResource($this->writes->cancel($model)))->resolve(),
        );
    }
}
