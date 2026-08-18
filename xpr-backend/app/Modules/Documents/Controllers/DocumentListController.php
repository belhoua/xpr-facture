<?php

declare(strict_types=1);

namespace App\Modules\Documents\Controllers;

use App\Modules\Documents\Resources\DocumentResource;
use App\Modules\Documents\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste paginée. Les lignes ne sont PAS chargées : un écran de liste affiche
 * des totaux, et charger les lignes de 25 documents pour ne rien en montrer
 * serait une jointure gratuite.
 */
final class DocumentListController
{
    public function __construct(private readonly DocumentService $documents) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->documents->paginate([
            // Valeur BRUTE : `type` s'accepte en liste CSV comme en paramètre
            // répété (`type[]=`), et `string()` transformerait le tableau en la
            // chaîne « Array ». La normalisation appartient au service, qui est
            // le seul à savoir ce qu'est un type valide.
            'type' => $request->input('type'),
            'status' => $request->string('status')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            'partnerId' => $request->string('partnerId')->toString() ?: null,
            // Les deux graphies, comme le résumé : les deux endpoints doivent
            // comprendre exactement les mêmes filtres, sans quoi les
            // indicateurs cesseraient de décrire les lignes affichées.
            'projectId' => $request->string('projectId')->toString()
                ?: ($request->string('project_id')->toString() ?: null),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
            'perPage' => $request->integer('perPage', 25),
            'page' => $request->integer('page', 1),
        ]);

        return response()->json([
            'data' => DocumentResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
