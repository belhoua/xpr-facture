<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Requests\FileDepositRequest;
use App\Modules\Conventions\Resources\FileDepositResource;
use App\Modules\Conventions\Services\FileDepositService;
use Illuminate\Http\JsonResponse;

final class FileDepositUpdateController
{
    public function __construct(private readonly FileDepositService $deposits) {}

    public function __invoke(FileDepositRequest $request, string $deposit): JsonResponse
    {
        $updated = $this->deposits->update(
            $this->deposits->findForCompany($deposit),
            $request->validated(),
        );

        return response()->json((new FileDepositResource($updated))->resolve());
    }
}
