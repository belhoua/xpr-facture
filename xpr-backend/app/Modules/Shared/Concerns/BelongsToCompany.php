<?php

declare(strict_types=1);

namespace App\Modules\Shared\Concerns;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scope global multi-tenant (CLAUDE.md §5.2) : toute requête sur un modèle
 * tenant est filtrée par la société active, et toute création reçoit son
 * company_id automatiquement — jamais depuis l'extérieur.
 *
 * Première ligne de défense ; la RLS PostgreSQL (RlsMigration) est la seconde.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $companyId = app(TenantContext::class)->currentId();

            if ($companyId !== null) {
                $builder->where($builder->qualifyColumn('company_id'), $companyId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                // requireId : créer une entité tenant sans contexte est un bug,
                // on refuse plutôt que d'insérer une ligne orpheline.
                $model->setAttribute('company_id', app(TenantContext::class)->requireId());
            }
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
