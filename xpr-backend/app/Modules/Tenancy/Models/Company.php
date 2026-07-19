<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Modules\Authentication\Models\User;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Le tenant. Table globale : PAS de trait BelongsToCompany ni de RLS ici —
 * l'accès est contrôlé par les Policies et l'appartenance via company_user.
 *
 * @property string $id uuid v7
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $legal_form
 * @property string|null $ice
 * @property bool $vat_exempt
 * @property string $default_currency
 * @property string $country
 * @property string $timezone
 */
final class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'legal_name',
        'trade_name',
        'legal_form',
        'ice',
        'if_number',
        'rc_number',
        'rc_city',
        'patente',
        'cnss',
        'share_capital',
        'vat_regime',
        'vat_exempt',
        'address',
        'city',
        'country',
        'phone',
        'email',
        'website',
        'default_currency',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'share_capital' => 'integer',
            'vat_exempt' => 'boolean',
        ];
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    /** @return BelongsToMany<User, $this, CompanyUser> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['invited_by', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }
}
