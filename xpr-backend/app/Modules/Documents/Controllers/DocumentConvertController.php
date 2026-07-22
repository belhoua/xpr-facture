<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentConversionService;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Devis → facture. Produit une FACTURE BROUILLON : la conversion propose, elle
 * n'émet pas. 201, parce qu'un nouveau document est bien créé.
 */
final class DocumentConvertController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentConversionService $conversions,
    ) {}

    public function __invoke(string $document): JsonResponse
    {
        $quote = $this->documents->findForCompany($document);

        return response()->json(
            (new DocumentResource($this->conversions->quoteToInvoice($quote)))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
