<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Volontairement pas de règle "exists:users" : la réponse doit être
        // identique que le compte existe ou non (anti-énumération, US-5).
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }
}
