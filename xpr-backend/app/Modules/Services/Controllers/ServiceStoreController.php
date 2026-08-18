<?php

declare(strict_types=1);

namespace App\Modules\Services\Controllers;

use App\Modules\Services\Models\Service;
use App\Modules\Services\Requests\ServiceStoreRequest;
use App\Modules\Services\Resources\ServiceResource;
use Illuminate\Http\JsonResponse;

/**
 * Création d'un service.
 *
 * Sans elle, le référentiel naîtrait vide et le déroulant de la modale projet
 * n'aurait jamais rien à proposer : la lecture seule aurait livré une
 * fonctionnalité inutilisable. `company_id` est posé par BelongsToCompany (§5),
 * jamais renseigné ici.
 */
final class ServiceStoreController
{
    public function __invoke(ServiceStoreRequest $request): JsonResponse
    {
        /** @var array{name: string} $data */
        $data = $request->validated();

        $service = Service::query()->create(['name' => trim($data['name'])]);

        return response()->json(
            (new ServiceResource($service))->resolve(),
            JsonResponse::HTTP_CREATED,
        );
    }
}
