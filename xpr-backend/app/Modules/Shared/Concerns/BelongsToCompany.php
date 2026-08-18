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
            $context = app(TenantContext::class);
            $companyId = $context->currentId();

            if ($companyId !== null) {
                $builder->where($builder->qualifyColumn('company_id'), $companyId);

                return;
            }

            // Utilisateur authentifié SANS société active : ne rien filtrer
            // reviendrait à tout montrer. C'était le comportement — un compte
            // détaché (invitation en attente, société retirée, rattachement
            // perdu) lisait les lignes de TOUTES les sociétés à travers
            // n'importe quelle liste. La RLS l'aurait arrêté en production,
            // mais elle est la SECONDE ligne de défense, pas la première, et
            // elle ne s'applique ni au rôle owner ni aux tests (§15).
            //
            // On renvoie donc l'ensemble vide : les écrans affichent leur état
            // vide en 200 au lieu d'exposer la base entière.
            if ($context->hasUserWithoutCompany()) {
                $builder->whereRaw('1 = 0');
            }

            // Aucun utilisateur authentifié (console, seeders, migrations,
            // jobs avant TenantAware) : pas de filtre, comme auparavant.
            // Y toucher casserait tout traitement hors requête.
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
