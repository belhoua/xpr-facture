<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentConversionService;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facture → avoir. C'est la SEULE façon légale de corriger une facture émise
 * (§3) : elle n'est jamais modifiée, un document de sens inverse lui est
 * rattaché. L'avoir naît brouillon, avec les lignes de la facture d'origine,
 * que l'utilisateur peut réduire avant de l'émettre (avoir partiel).
 */
final class DocumentCreditNoteController
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentConversionService $conversions,
    ) {}

    public function __invoke(string $document): JsonResponse
    {
        $invoice = $this->documents->findForCompany($document);

        return response()->json(
            (new DocumentResource($this->conversions->invoiceToCreditNote($invoice)))->resolve(),
            Response::HTTP_CREATED,
        );
    }
}
