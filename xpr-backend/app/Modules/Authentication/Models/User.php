<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Models;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Compte global : un utilisateur peut appartenir à N sociétés (cabinets
 * comptables). Tout ce qui est tenant passe par la relation companies().
 *
 * @property string $id uuid v7
 * @property string $name
 * @property string $email
 * @property string $locale
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $created_at
 * @property string|null $default_company_id
 */
final class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'default_company_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** Notifications (reset, vérification) envoyées dans la langue du compte. */
    public function preferredLocale(): string
    {
        return $this->locale;
    }

    /** @return BelongsToMany<Company, $this, CompanyUser> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot(['invited_by', 'invited_at', 'joined_at'])
            ->withTimestamps();
    }

    /**
     * Société active à la connexion : la préférence enregistrée si elle est
     * toujours valide, sinon la première appartenance effective.
     */
    public function resolveActiveCompany(): ?Company
    {
        $memberships = $this->companies()->whereNotNull('joined_at');

        if ($this->default_company_id !== null) {
            $preferred = (clone $memberships)->whereKey($this->default_company_id)->first();

            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $memberships->first();
    }
}
