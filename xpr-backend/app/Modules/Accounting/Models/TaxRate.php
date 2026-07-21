<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\TaxKind;
use App\Modules\Shared\Concerns\BelongsToCompanyOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Taux de TVA. `company_id` NULL = catalogue standard marocain, partagé et
 * lisible par toutes les sociétés ; une ligne portant un company_id est un taux
 * propre à cette société (décision du 2026-07-21).
 *
 * @property string $id
 * @property string|null $company_id
 * @property string $label_fr
 * @property string $label_ar
 * @property numeric-string $rate
 * @property TaxKind $kind
 * @property bool $is_default
 * @property bool $is_active
 */
final class TaxRate extends Model
{
    use BelongsToCompanyOrGlobal;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'label_fr',
        'label_ar',
        'rate',
        'kind',
        'is_default',
        'is_active',
    ];

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Taux en centièmes de point (20,00 % → 2000). Les montants sont calculés
     * en entiers (§7) : introduire un float ici ruinerait la précision de toute
     * la chaîne de calcul de la TVA.
     */
    public function rateBasisPoints(): int
    {
        return (int) round((float) $this->rate * 100);
    }

    protected function casts(): array
    {
        return [
            // decimal:2 conserve une chaîne exacte, jamais un float
            'rate' => 'decimal:2',
            'kind' => TaxKind::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
