<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Requests;

use App\Modules\AdminNotes\Enums\NotePriority;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateAdminNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Bornes identiques à `createNoteSchema` côté front. La duplication est
     * assumée : le frontend ne protège rien (§10), c'est ici que ça compte.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'min:4', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'priority' => ['required', Rule::enum(NotePriority::class)],
        ];
    }
}
