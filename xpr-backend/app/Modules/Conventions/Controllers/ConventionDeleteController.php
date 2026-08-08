<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Services\ConventionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConventionDeleteController
{
    public function __construct(private readonly ConventionService $conventions) {}

    /** Le service répond 409 sur une convention SIGNÉE : elle s'annule, ne se supprime pas. */
    public function __invoke(string $convention): JsonResponse
    {
        $this->conventions->delete($this->conventions->findForCompany($convention));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
