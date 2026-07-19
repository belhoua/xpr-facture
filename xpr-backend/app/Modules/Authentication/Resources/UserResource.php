<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Resources;

use App\Modules\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Contrat JSON du compte. Exposition explicite champ par champ : un nouvel
 * attribut en base n'apparaît JAMAIS dans l'API sans décision ici.
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'default_company_id' => $this->default_company_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
