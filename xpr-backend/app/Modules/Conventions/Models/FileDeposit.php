<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Models;

use App\Modules\Conventions\Enums\DepositStatus;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Dépôt d'un dossier auprès d'un organisme instructeur.
 *
 * @property string $id
 * @property string $company_id
 * @property string $convention_id
 * @property string $reference
 * @property Carbon $deposited_at
 * @property string $organisation
 * @property DepositStatus $status
 * @property Carbon|null $decided_at
 * @property string|null $notes
 */
final class FileDeposit extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'convention_id',
        'reference',
        'deposited_at',
        'organisation',
        'status',
        'decided_at',
        'notes',
    ];

    /** @return BelongsTo<Convention, $this> */
    public function convention(): BelongsTo
    {
        return $this->belongsTo(Convention::class);
    }

    /**
     * Recherche libre sur la référence du récépissé et l'organisme.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('reference', 'ILIKE', "%{$escaped}%")
                ->orWhere('organisation', 'ILIKE', "%{$escaped}%");
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DepositStatus::class,
            'deposited_at' => 'date',
            'decided_at' => 'date',
        ];
    }
}
