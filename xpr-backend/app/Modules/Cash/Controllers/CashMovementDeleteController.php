<?php

declare(strict_types=1);

namespace App\Modules\Cash\Controllers;

use App\Modules\Cash\Models\CashMovement;
use App\Modules\Cash\Services\CashMovementWriteService;
use Illuminate\Http\Response;

/**
 * Résolution dans le contrôleur (après `tenant`) pour rester sous le scope
 * BelongsToCompany — cf. CashMovementUpdateController.
 */
final class CashMovementDeleteController
{
    public function __construct(private readonly CashMovementWriteService $movements) {}

    public function __invoke(string $movement): Response
    {
        $model = CashMovement::query()->findOrFail($movement);

        $this->movements->delete($model);

        return response()->noContent();
    }
}
