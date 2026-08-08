<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Resources\FileDepositResource;
use App\Modules\Conventions\Services\FileDepositService;
use Illuminate\Http\JsonResponse;

/** Sert la fiche de dépôt imprimable, qui a besoin du dépôt ET de son projet. */
final class FileDepositShowController
{
    public function __construct(private readonly FileDepositService $deposits) {}

    public function __invoke(string $deposit): JsonResponse
    {
        return response()->json(
            (new FileDepositResource($this->deposits->findForCompany($deposit)))->resolve(),
        );
    }
}
