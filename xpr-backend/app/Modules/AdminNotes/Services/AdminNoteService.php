<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Services;

use App\Modules\AdminNotes\Enums\NotePriority;
use App\Modules\AdminNotes\Models\AdminNote;
use Illuminate\Database\Eloquent\Collection;

final class AdminNoteService
{
    /**
     * Notes de la société active, plus récentes d'abord. Le filtrage par
     * société est assuré par le global scope BelongsToCompany — aucun
     * `where('company_id', ...)` ici, il serait redondant et trompeur.
     *
     * @return Collection<int, AdminNote>
     */
    public function listForActiveCompany(?string $status = null): Collection
    {
        $query = AdminNote::query()->orderByDesc('created_at');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /**
     * @param  array{subject: string, body: string, priority: string}  $payload
     */
    public function create(array $payload, ?string $authorId): AdminNote
    {
        $note = AdminNote::create([
            'subject' => $payload['subject'],
            'body' => $payload['body'],
            'priority' => NotePriority::from($payload['priority']),
            'created_by' => $authorId,
            // `status` non renseigné : la valeur par défaut 'open' vient de
            // PostgreSQL, et seul le support la fait évoluer ensuite.
        ]);

        // Indispensable : sans rechargement, `status` est null en mémoire et
        // la sérialisation (`$this->status->value`) échouerait.
        return $note->refresh();
    }
}
