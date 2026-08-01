<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Models;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Models\CompanyUser;
use Database\Factories\UserFactory;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

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

    /**
     * Envoi best-effort du lien de vérification.
     *
     * Le listener SendEmailVerificationNotification est déclenché par l'event
     * Registered, donc DANS la requête d'inscription et APRÈS le commit. Sans
     * worker de file d'attente — c'est le cas en serverless — l'appel au
     * transport est synchrone : un SMTP injoignable ou une API mail en timeout
     * fait alors remonter une TransportException, et le client reçoit un 500
     * pour un compte qui a bel et bien été créé. Le pire des deux mondes : il
     * ne peut ni se connecter (il croit l'inscription échouée) ni recommencer
     * (l'e-mail est déjà pris).
     *
     * La remise d'un e-mail est un incident d'infrastructure, pas une erreur du
     * client : on la trace et l'appelant garde sa réponse. La route de renvoi
     * répond déjà 202 Accepted — « accepté pour traitement », jamais « remis ».
     * L'utilisateur peut la rappeler une fois le transport rétabli.
     */
    public function sendEmailVerificationNotification(): void
    {
        try {
            $this->notify(new VerifyEmail);
        } catch (Throwable $e) {
            Log::error('Envoi du lien de vérification impossible', [
                'user_id' => $this->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
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
