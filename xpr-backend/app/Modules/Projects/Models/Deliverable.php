<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Livrable remis au client : notice technique, rapport d'avancement,
 * procès-verbal.
 *
 * SOFT DELETE : une remise retirée a eu lieu, et l'effacer durement ferait
 * disparaître la seule trace qu'un document est bien parti chez le client —
 * précisément ce qu'on vient vérifier quand il affirme le contraire.
 *
 * @property string $id
 * @property string $company_id
 * @property string $project_id
 * @property string $title
 * @property Carbon $delivery_date
 * @property string|null $notes
 * @property string|null $created_by
 */
final class Deliverable extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'title',
        'delivery_date',
        'notes',
        'created_by',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
        ];
    }
}
