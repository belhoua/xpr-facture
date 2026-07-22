<?php

declare(strict_types=1);

namespace App\Modules\Documents\Requests;

use App\Modules\Documents\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changement d'état d'un document émis.
 *
 * La validation ne contrôle ici que l'APPARTENANCE à l'énumération : savoir si
 * la transition est permise pour CE document, dans SON état courant, dépend de
 * la ligne en base et relève donc du service (qui répond 409, pas 422).
 */
final class DocumentStatusRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(DocumentStatus::class)],
        ];
    }
}
