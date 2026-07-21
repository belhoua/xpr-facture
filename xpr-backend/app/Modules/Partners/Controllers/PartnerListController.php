<?php

declare(strict_types=1);

namespace App\Modules\Partners\Controllers;

use App\Modules\Partners\Resources\PartnerResource;
use App\Modules\Partners\Services\PartnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PartnerListController
{
    public function __construct(private readonly PartnerService $partners) {}

    public function __invoke(Request $request): JsonResponse
    {
        $paginator = $this->partners->paginate([
            'type' => $request->string('type')->toString() ?: null,
            'search' => $request->string('search')->toString() ?: null,
            // `has` et non `boolean` : sans le paramètre, on ne filtre pas —
            // `boolean()` rendrait false et masquerait les fiches actives.
            'active' => $request->has('active') ? $request->boolean('active') : null,
            'perPage' => $request->integer('perPage', 25),
        ]);

        return response()->json([
            'data' => PartnerResource::collection($paginator->items())->resolve(),
            'meta' => [
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
            ],
        ]);
    }
}
