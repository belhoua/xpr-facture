<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Resources;

use App\Modules\AdminNotes\Models\AdminNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat camelCase aligné sur `features/admin-notes/schemas/note.ts`.
 * `createdAt` sort en ISO 8601 : le front le valide via z.iso.datetime().
 *
 * @mixin AdminNote
 */
final class AdminNoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'body' => $this->body,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'createdAt' => $this->created_at->toIso8601ZuluString('microseconds'),
        ];
    }
}
