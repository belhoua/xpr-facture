<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Resources;

use App\Modules\Authentication\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Membre d'une société tel qu'attendu par `features/users/schemas/user.ts`.
 *
 * @mixin User
 */
final class CompanyMemberResource extends JsonResource
{
    /**
     * @param  array{role: string, state: string}  $memberMeta
     */
    public function __construct(
        User $resource,
        private readonly array $memberMeta,
    ) {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->memberMeta['role'],
            'state' => $this->memberMeta['state'],
            'lastActiveAt' => null,
        ];
    }
}
