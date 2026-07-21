<?php

declare(strict_types=1);

namespace App\Modules\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la modification d'un mouvement de caisse
 * (PATCH /api/v1/cash/movements/{id}). Le formulaire d'édition renvoie tous
 * les champs — mêmes règles que la création.
 */
final class CashMovementUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'occurredAt' => ['required', 'date'],
            'label' => ['required', 'string', 'min:2', 'max:255'],
            'method' => ['required', Rule::in(['cash', 'cheque', 'transfer', 'card', 'effect'])],
            'registerName' => ['required', 'string', 'min:1', 'max:255'],
            'amountCents' => ['required', 'integer', 'not_in:0', 'between:-9999999999,9999999999'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
