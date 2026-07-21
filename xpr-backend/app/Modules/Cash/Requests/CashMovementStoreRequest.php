<?php

declare(strict_types=1);

namespace App\Modules\Cash\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation d'un mouvement de caisse (POST /api/v1/cash/movements).
 *
 * `amountCents` est SIGNÉ (positif = encaissement, négatif = décaissement),
 * cohérent avec le schéma de lecture. Un montant nul n'a pas de sens
 * comptable : on l'interdit explicitement plutôt que de laisser passer une
 * ligne fantôme dans le journal.
 */
final class CashMovementStoreRequest extends FormRequest
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
