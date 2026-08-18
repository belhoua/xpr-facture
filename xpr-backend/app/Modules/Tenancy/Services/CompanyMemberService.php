<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class CompanyMemberService
{
    public function __construct(private readonly PermissionRegistrar $permissions) {}

    /** @return Collection<int, array{user: User, role: string, state: string}> */
    public function listMembers(Company $company): Collection
    {
        $previousTeamId = $this->permissions->getPermissionsTeamId();
        $this->permissions->setPermissionsTeamId($company->id);

        try {
            // Les RÔLES sont chargés avec la liste, et c'est le seul moyen :
            // `preventLazyLoading` est actif hors production (AppServiceProvider),
            // si bien que lire `$user->roles` membre par membre faisait échouer
            // tout l'écran — pas seulement le rendre lent.
            //
            // Le chargement a lieu ICI, après `setPermissionsTeamId` : la
            // relation `roles` de Spatie contraint le pivot sur le team COURANT
            // au moment où la requête part. Remonter ce `with()` avant le
            // périmètre lirait les rôles du périmètre précédent — soit `null`,
            // qui n'en rend aucun.
            $members = $company->users()
                ->withPivot(['invited_at', 'joined_at'])
                ->with('roles')
                ->orderBy('name')
                ->get();

            $mapped = [];

            foreach ($members as $user) {
                // Plus de `unsetRelation('roles')` ici : il vidait la relation
                // qu'on vient de charger, et le rechargement qui suivait était
                // précisément le lazy load interdit. Sa raison d'être — ne pas
                // servir les rôles d'un autre périmètre — est désormais tenue
                // par l'ordre des opérations plutôt que par une purge.

                /** @var object{joined_at: ?string}|null $pivot */
                $pivot = $user->getAttribute('pivot');

                /** @var Role|null $role */
                $role = $user->roles->first();

                $mapped[] = [
                    'user' => $user,
                    'role' => $role instanceof Role ? $role->name : 'viewer',
                    'state' => $pivot?->joined_at !== null ? 'active' : 'invited',
                ];
            }

            /** @var Collection<int, array{user: User, role: string, state: string}> */
            return new Collection($mapped);
        } finally {
            $this->permissions->setPermissionsTeamId($previousTeamId);
        }
    }

    /**
     * @param  array{name: string, email: string, role: string}  $payload
     */
    public function invite(Company $company, User $inviter, array $payload): User
    {
        return DB::transaction(function () use ($company, $inviter, $payload): User {
            $email = Str::lower($payload['email']);

            $existingMembership = $company->users()->where('email', $email)->exists();
            if ($existingMembership) {
                throw ValidationException::withMessages([
                    'email' => [__('validation.unique', ['attribute' => 'email'])],
                ]);
            }

            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                $user = User::create([
                    'name' => $payload['name'],
                    'email' => $email,
                    'password' => Hash::make(Str::random(32)),
                    // L'invité hérite de la langue de l'inviteur. Repli sur la
                    // locale applicative : `users.locale` est NOT NULL et sa
                    // valeur par défaut vient de PostgreSQL — une instance non
                    // rechargée la porte à null et ferait échouer l'insertion.
                    'locale' => $inviter->locale ?? config('app.locale'),
                ]);
            }

            $company->users()->attach($user->id, [
                'invited_by' => $inviter->id,
                'invited_at' => now(),
                'joined_at' => null,
            ]);

            $previousTeamId = $this->permissions->getPermissionsTeamId();
            $this->permissions->setPermissionsTeamId($company->id);

            try {
                $user->syncRoles([Role::findByName($payload['role'], 'web')]);
            } finally {
                $this->permissions->setPermissionsTeamId($previousTeamId);
            }

            return $user;
        });
    }
}
