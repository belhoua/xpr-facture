<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Requests\FileDepositRequest;
use App\Modules\Conventions\Resources\FileDepositResource;
use App\Modules\Conventions\Services\ConventionService;
use App\Modules\Conventions\Services\FileDepositService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enregistre un dépôt SUR une convention. La convention vient du chemin et est
 * résolue sous le scope tenant — jamais du corps de la requête (§5.3).
 */
final class FileDepositStoreController
{
    public function __construct(
        private readonly ConventionService $conventions,
        private readonly FileDepositService $deposits,
    ) {}

    public function __invoke(FileDepositRequest $request, string $convention): JsonResponse
    {
        $deposit = $this->deposits->create(
            $this->conventions->findForCompany($convention),
            $request->validated(),
        );

        return response()->json(
            (new FileDepositResource($deposit))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
