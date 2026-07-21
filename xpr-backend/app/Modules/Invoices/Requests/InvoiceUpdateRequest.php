<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Requests;

use App\Modules\Tenancy\Services\TenantContext;
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
            // Mêmes règles qu'à la création : le tiers doit appartenir à la
            // société active, et le nom n'est requis qu'en l'absence de tiers.
            'partnerId' => [
                'nullable',
                'uuid',
                Rule::exists('partners', 'id')
                    ->where('company_id', app(TenantContext::class)->requireId())
                    ->whereNull('deleted_at'),
            ],
            'clientName' => ['required_without:partnerId', 'nullable', 'string', 'min:2', 'max:255'],
            'issuedAt' => ['nullable', 'date'],
            'dueAt' => ['nullable', 'date', 'after_or_equal:issuedAt'],
            'status' => ['required', Rule::in(['draft', 'sent', 'partial', 'paid', 'overdue'])],
            'totalCents' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
