<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation de la création d'une facture (POST /api/v1/invoices).
 *
 * Le front envoie du camelCase (cf. InvoiceResource / schéma Zod) ; on valide
 * donc les clés camelCase et le contrôleur les traduit vers les colonnes
 * snake_case du modèle. Le statut `cancelled` est EXCLU ici : une annulation
 * passe par l'endpoint dédié (immuabilité §3), jamais par une création directe.
 */
final class InvoiceStoreRequest extends FormRequest
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
            // Montant reçu EN CENTIMES (entier) — jamais de flottant (§7).
            'totalCents' => ['required', 'integer', 'min:0', 'max:9999999999'],
            'currency' => ['required', 'string', 'size:3'],
        ];
    }
}
