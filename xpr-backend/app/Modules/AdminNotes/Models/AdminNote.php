<?php

declare(strict_types=1);

namespace App\Modules\AdminNotes\Models;

use App\Modules\AdminNotes\Enums\NotePriority;
use App\Modules\AdminNotes\Enums\NoteStatus;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $created_by
 * @property string $subject
 * @property string $body
 * @property NotePriority $priority
 * @property NoteStatus $status
 * @property Carbon $created_at
 */
final class AdminNote extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    /**
     * `company_id` et `status` en sont volontairement absents : le premier est
     * posé par BelongsToCompany (§5.3), le second relève du support plateforme.
     */
    protected $fillable = [
        'subject',
        'body',
        'priority',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'priority' => NotePriority::class,
            'status' => NoteStatus::class,
        ];
    }
}
