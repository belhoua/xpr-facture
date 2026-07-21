<?php

declare(strict_types=1);

namespace App\Modules\Cash\Controllers;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Requests\CashMovementUpdateRequest;
use App\Modules\Cash\Resources\CashMovementResource;
use App\Modules\Cash\Services\CashMovementWriteService;
use Illuminate\Http\JsonResponse;

/**
 * Résolution dans le contrôleur (après `tenant`) pour rester sous le scope
 * BelongsToCompany : un mouvement d'une autre société renvoie 404 (§5).
 */
final class CashMovementUpdateController
{
    public function __construct(private readonly CashMovementWriteService $movements) {}

    public function __invoke(CashMovementUpdateRequest $request, string $movement): JsonResponse
    {
        $model = CashMovement::query()->findOrFail($movement);

        /** @var array{occurredAt: string, label: string, method: string, registerName: string, amountCents: int, currency: string} $data */
        $data = $request->validated();

        return response()->json(
            (new CashMovementResource($this->movements->update($model, $data)))->resolve(),
        );
    }
}
