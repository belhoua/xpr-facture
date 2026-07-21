<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Controllers;

use App\Modules\AdminNotes\Resources\AdminNoteResource;
use App\Modules\AdminNotes\Services\AdminNoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminNoteListController
{
    public function __construct(private readonly AdminNoteService $notes) {}

    public function __invoke(Request $request): JsonResponse
    {
        $notes = $this->notes->listForActiveCompany(
            status: $request->string('status')->toString() ?: null,
        );

        // Enveloppe `{ data: [...] }` attendue par adminNoteListSchema.
        return response()->json([
            'data' => AdminNoteResource::collection($notes)->resolve(),
        ]);
    }
}
