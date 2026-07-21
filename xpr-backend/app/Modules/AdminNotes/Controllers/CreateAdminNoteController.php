<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Controllers;

use App\Modules\AdminNotes\Requests\CreateAdminNoteRequest;
use App\Modules\AdminNotes\Resources\AdminNoteResource;
use App\Modules\AdminNotes\Services\AdminNoteService;
use Illuminate\Http\JsonResponse;

final class CreateAdminNoteController
{
    public function __construct(private readonly AdminNoteService $notes) {}

    public function __invoke(CreateAdminNoteRequest $request): JsonResponse
    {
        $note = $this->notes->create(
            payload: [
                'subject' => (string) $request->validated('subject'),
                'body' => (string) $request->validated('body'),
                'priority' => (string) $request->validated('priority'),
            ],
            authorId: $request->user()?->getAuthIdentifier() !== null
                ? (string) $request->user()->getAuthIdentifier()
                : null,
        );

        // Objet nu (pas d'enveloppe `data`) : createAdminNote() côté front
        // parse la réponse avec adminNoteSchema directement.
        return response()->json((new AdminNoteResource($note))->resolve(), 201);
    }
}
