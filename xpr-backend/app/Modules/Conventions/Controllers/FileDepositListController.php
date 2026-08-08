<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Controllers;

use App\Modules\Conventions\Resources\FileDepositResource;
use App\Modules\Conventions\Services\FileDepositService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Liste TRANSVERSE des dépôts de dossier : tous projets confondus, filtrable
 * par convention.
 *
 * Une liste globale et pas seulement l'onglet d'une convention, parce que la
 * question qu'on pose le matin est « quels dossiers attendent une réponse »,
 * pas « où en est le dossier de M. X » — et elle traverse les conventions.
 */
final class FileDepositListController
{
    public function __construct(private readonly FileDepositService $deposits) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->deposits->paginate([
            'search' => $request->string('search')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'conventionId' => $request->string('conventionId')->toString() ?: null,
            'perPage' => $request->integer('perPage', 25),
        ]);

        return response()->json([
            'data' => FileDepositResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
