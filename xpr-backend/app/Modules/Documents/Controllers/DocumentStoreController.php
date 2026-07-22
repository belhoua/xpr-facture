<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Requests\DocumentStoreRequest;
use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class DocumentStoreController
{
    public function __construct(private readonly DocumentWriteService $documents) {}

    public function __invoke(DocumentStoreRequest $request): JsonResponse
    {
        return response()->json(
            (new DocumentResource($this->documents->create($request->validated())))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
