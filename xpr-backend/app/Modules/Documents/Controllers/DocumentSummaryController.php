<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Totaux d'un ensemble de documents : nombre, montant cumulé, encaissé, solde.
 *
 * Alimente les quatre cartes de l'écran « situations par client ». Endpoint
 * séparé de la liste plutôt qu'un bloc `meta` sur celle-ci : la page 2 d'une
 * liste renverrait les mêmes totaux et les recalculerait pour rien, alors que
 * les indicateurs, eux, ne changent pas d'une page à l'autre.
 *
 * Accepte les mêmes filtres que la liste, à la pagination près — les chiffres
 * affichés doivent décrire exactement les lignes en dessous.
 */
final class DocumentSummaryController
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->documents->summary([
            'type' => $request->string('type')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'partnerId' => $request->string('partnerId')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ]));
    }
}
