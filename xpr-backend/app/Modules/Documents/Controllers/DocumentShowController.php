<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;

final class DocumentShowController
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(string $document): JsonResponse
    {
        return response()->json(
            (new DocumentResource($this->documents->findForCompany($document)))->resolve(),
        );
    }
}
