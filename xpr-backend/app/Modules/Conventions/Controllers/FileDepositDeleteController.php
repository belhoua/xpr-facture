<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Services\FileDepositService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class FileDepositDeleteController
{
    public function __construct(private readonly FileDepositService $deposits) {}

    public function __invoke(string $deposit): JsonResponse
    {
        $this->deposits->delete($this->deposits->findForCompany($deposit));

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
