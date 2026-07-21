<?php

declare(strict_types=1);

namespace App\Modules\Cash\Controllers;

use App\Modules\Cash\Requests\CashMovementStoreRequest;
use App\Modules\Cash\Resources\CashMovementResource;
use App\Modules\Cash\Services\CashMovementWriteService;
use Illuminate\Http\JsonResponse;

final class CashMovementStoreController
{
    public function __construct(private readonly CashMovementWriteService $movements) {}

    public function __invoke(CashMovementStoreRequest $request): JsonResponse
    {
        /** @var array{occurredAt: string, label: string, method: string, registerName: string, amountCents: int, currency: string} $data */
        $data = $request->validated();

        $movement = $this->movements->create($data);

        // ->resolve() : contrat plat sans enveloppe `data` (convention du dépôt).
        return response()->json((new CashMovementResource($movement))->resolve(), 201);
    }
}
