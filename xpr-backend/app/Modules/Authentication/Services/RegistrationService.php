<?php

declare(strict_types=1);

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\DTO\RegisterData;
use App\Modules\Authentication\Events\UserRegistered;
use App\Modules\Authentication\Models\User;
use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\CompanyProvisioning;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;

/**
 * US-1 : compte + société + rôle owner en UNE transaction. Jamais de compte
 * orphelin. Les événements (e-mail de vérification, audit) partent APRÈS le
 * commit : on ne notifie rien qui puisse être annulé par un rollback.
 */
final class RegistrationService
{
    public function __construct(private readonly CompanyProvisioning $provisioning) {}

    /** @return array{user: User, company: Company} */
    public function register(RegisterData $data): array
    {
        /** @var array{user: User, company: Company} $account */
        $account = DB::transaction(function () use ($data): array {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password, // hachage via cast 'hashed'
                'locale' => $data->locale,
            ]);

            $company = $this->provisioning->createFirstCompanyFor(
                $user,
                $data->companyLegalName,
                $data->companyLegalForm,
            );

            return ['user' => $user, 'company' => $company];
        });

        event(new Registered($account['user']));
        event(new UserRegistered($account['user'], $account['company']));

        return $account;
    }
}
