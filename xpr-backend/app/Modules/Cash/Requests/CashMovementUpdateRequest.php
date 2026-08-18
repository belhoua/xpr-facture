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
            // Tiers concerné. FACULTATIF : un décaissement n'en a souvent
            // aucun (loyer, fournitures). Validé comme un UUID mais son
            // EXISTENCE ne l'est pas ici — `Rule::exists('partners', 'id')`
            // interroge la table sans le global scope de société et accepterait
            // le tiers d'un autre tenant. La vérification revient au service,
            // qui la fait sous le scope (§5.3).
            'partnerId' => ['nullable', 'uuid'],
            'occurredAt' => ['required', 'date'],
            'label' => ['required', 'string', 'min:2', 'max:255'],
            'method' => ['required', Rule::in(['cash', 'cheque', 'transfer', 'card', 'effect'])],
            'registerName' => ['required', 'string', 'min:1', 'max:255'],
            'amountCents' => ['required', 'integer', 'not_in:0', 'between:-9999999999,9999999999'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
