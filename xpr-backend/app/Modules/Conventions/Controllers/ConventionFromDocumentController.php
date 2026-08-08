<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Resources\ConventionResource;
use App\Modules\Conventions\Services\ConventionDraftingService;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Devis / facture → contrat de convention. Produit un BROUILLON de convention :
 * le transfert épargne une ressaisie, il n'engage rien. 201, un objet est créé.
 *
 * Le document source est résolu par `DocumentService` sous le scope tenant : la
 * route déclare `{document}` en paramètre simple, jamais en binding
 * (cf. tests/Feature/Tenancy/RouteBindingScopeTest.php).
 */
final class ConventionFromDocumentController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly ConventionDraftingService $drafting,
    ) {}

    public function __invoke(string $document): JsonResponse
    {
        $source = $this->documents->findForCompany($document);

        return response()->json(
            (new ConventionResource($this->drafting->fromDocument($source)))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
