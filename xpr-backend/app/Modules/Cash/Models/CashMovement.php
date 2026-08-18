<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Modules\Partners\Models\Partner;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $partner_id
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
        'partner_id',
        'occurred_at',
        'label',
        'method',
        'register_name',
        'amount_cents',
        'currency',
    ];

    /**
     * Tiers concerné par le mouvement.
     *
     * NULLABLE : un décaissement n'a souvent aucun tiers de ce répertoire —
     * un loyer, un plein de carburant. La relation peut aussi être chargée ET
     * nulle, `Partner` portant un soft delete : une écriture de caisse est un
     * fait comptable, elle survit à l'archivage du tiers.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

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
