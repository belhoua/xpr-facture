<?php

declare(strict_types=1);

namespace App\Modules\Projects\Controllers;

use App\Modules\Projects\Services\ProjectService;
use App\Modules\Projects\Services\ProjectWriteService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Retrait d'un livrable, par SON identifiant et sans le projet dans le chemin :
 * un livrable n'appartient qu'à un projet, le répéter dans l'URL ouvrirait la
 * question de leur désaccord. Il est déjà résolu sous le scope tenant.
 */
final class DeliverableDeleteController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectWriteService $writes,
    ) {}

    public function __invoke(string $deliverable): JsonResponse
    {
        $this->writes->deleteDeliverable(
            $this->projects->findDeliverableForCompany($deliverable),
        );

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
