<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Exercice comptable. Périmètre de remise à zéro de la numérotation : c'est lui
 * qui fait repartir FAC-2026-0001 à 0001 au changement d'exercice.
 *
 * @property string $id
 * @property string $company_id
 * @property string $label
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property FiscalYearStatus $status
 */
final class FiscalYear extends Model
{
    use BelongsToCompany;
    use HasUuids;

    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'status',
    ];

    /** @return HasMany<Sequence, $this> */
    public function sequences(): HasMany
    {
        return $this->hasMany(Sequence::class);
    }

    /**
     * Exercice couvrant une date donnée. La contrainte d'exclusion GiST garantit
     * qu'il y en a au plus un : pas d'ambiguïté possible sur la séquence à
     * utiliser.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeCovering(Builder $query, Carbon $date): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date);
    }

    /** L'année portée par le placeholder {YYYY} des formats de numérotation. */
    public function numberingYear(): string
    {
        // Année de DÉBUT d'exercice : sur un exercice décalé (juillet → juin),
        // faire varier le préfixe en cours d'exercice donnerait l'apparence
        // d'une séquence trouée à un contrôleur fiscal.
        return $this->starts_on->format('Y');
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalYearStatus::Open;
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalYearStatus::class,
        ];
    }
}
