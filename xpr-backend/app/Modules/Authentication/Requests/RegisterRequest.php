<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Requests;

use App\Modules\Tenancy\Enums\LegalForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // route publique
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            // Unicité parmi les comptes ACTIFS uniquement : un compte
            // soft-deleted libère son e-mail (index partiel en base).
            'email' => [
                'required', 'string', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],
            // Pas de champ "confirmation" : friction inutile, le frontend
            // offre l'affichage du mot de passe (décision analyse §3).
            'password' => ['required', 'string', Password::min(8)],
            'company_legal_name' => ['required', 'string', 'max:255'],
            'company_legal_form' => ['required', Rule::enum(LegalForm::class)],
            'locale' => ['required', 'string', Rule::in(['fr', 'ar', 'en'])],
        ];
    }
}
