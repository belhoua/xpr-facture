<?php

declare(strict_types=1);

namespace App\Modules\Authentication\DTO;

use App\Modules\Authentication\Requests\RegisterRequest;
use App\Modules\Tenancy\Enums\LegalForm;

/**
 * Données d'inscription validées, typées, immuables — la frontière entre la
 * couche HTTP (FormRequest) et le métier (RegistrationService).
 */
final readonly class RegisterData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $companyLegalName,
        public LegalForm $companyLegalForm,
        public string $locale,
    ) {}

    public static function fromRequest(RegisterRequest $request): self
    {
        return new self(
            name: $request->string('name')->value(),
            email: $request->string('email')->value(),
            password: $request->string('password')->value(),
            companyLegalName: $request->string('company_legal_name')->value(),
            companyLegalForm: LegalForm::from($request->string('company_legal_form')->value()),
            locale: $request->string('locale')->value(),
        );
    }
}
