<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la modification d'une facture (PATCH /api/v1/invoices/{id}).
 *
 * Mêmes règles que la création : le formulaire d'édition renvoie l'intégralité
 * des champs. Le verrou d'immuabilité (édition réservée aux brouillons) est
 * appliqué dans le service, pas ici — il dépend de l'état PERSISTÉ, que le
 * FormRequest ne connaît pas.
 */
final class InvoiceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clientName' => ['required', 'string', 'min:2', 'max:255'],
            'issuedAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'status' => ['required', Rule::in(['draft', 'sent', 'partial', 'paid', 'overdue'])],
            'totalCents' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
