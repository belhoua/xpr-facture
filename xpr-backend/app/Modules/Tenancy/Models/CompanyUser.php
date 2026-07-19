<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot d'appartenance utilisateur ↔ société.
 *
 * Volontairement SANS BelongsToCompany : ce pivot sert à résoudre le contexte
 * tenant (« à quelles sociétés appartient cet utilisateur ? ») et doit donc
 * rester interrogeable avant qu'une société soit active. Sa RLS est une
 * policy dédiée (par user_id OU company_id), cf. migration company_user.
 */
final class CompanyUser extends Pivot
{
    use HasUuids;

    protected $table = 'company_user';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }
}
