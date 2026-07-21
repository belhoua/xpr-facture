<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property Carbon $occurred_at
 * @property string $label
 * @property string $method
 * @property string $register_name
 * @property int $amount_cents
 * @property string $currency
 */
final class CashMovement extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'occurred_at',
        'label',
        'method',
        'register_name',
        'amount_cents',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }
}
