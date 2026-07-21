<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:150'],
            // 'rfc' sans 'dns', par cohérence avec RegisterRequest : la
            // résolution DNS ajoute une dépendance réseau à chaque appel et
            // rejetait des domaines qu'une inscription accepte.
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['admin', 'accountant', 'sales', 'viewer'])],
        ];
    }
}
