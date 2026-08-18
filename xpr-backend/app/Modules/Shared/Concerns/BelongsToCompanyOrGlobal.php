<?php

declare(strict_types=1);

namespace App\Modules\Shared\Concerns;

use App\Modules\Tenancy\Models\Company;
use App\Modules\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Variante de BelongsToCompany pour les tables qui mêlent un RÉFÉRENTIEL
 * GLOBAL (company_id NULL) et des lignes propres à chaque société :
 * `tax_rates`, `settings`.
 *
 * Lecture : la société voit ses lignes ET le référentiel global.
 * Écriture : company_id est toujours renseigné — l'application ne produit
 * jamais de ligne globale, seuls les seeders de référentiel le font.
 *
 * Le pendant PostgreSQL est RlsMigration::applyWithGlobalRows().
 */
trait BelongsToCompanyOrGlobal
{
    public static function bootBelongsToCompanyOrGlobal(): void
    {
        static::addGlobalScope('companyOrGlobal', function (Builder $builder): void {
            $context = app(TenantContext::class);
            $companyId = $context->currentId();

            if ($companyId === null) {
                // Utilisateur authentifié sans société : il ne voit que le
                // référentiel GLOBAL (taux de TVA standard), jamais les lignes
                // qu'une société a ajoutées pour elle-même. Même raisonnement
                // que dans BelongsToCompany, à ceci près qu'ici l'ensemble vide
                // serait faux : le référentiel partagé n'appartient à personne.
                if ($context->hasUserWithoutCompany()) {
                    $builder->whereNull($builder->qualifyColumn('company_id'));
                }

                return;
            }

            $column = $builder->qualifyColumn('company_id');

            // Parenthésé : sans le groupe, un ->where() ajouté par l'appelant
            // se retrouverait en OR avec les lignes globales et ferait fuiter
            // tout le référentiel hors du filtre demandé.
            $builder->where(function (Builder $query) use ($column, $companyId): void {
                $query->where($column, $companyId)->orWhereNull($column);
            });
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', app(TenantContext::class)->requireId());
            }
        });
    }

    /** Vrai pour une ligne du référentiel standard, non modifiable par la société. */
    public function isGlobal(): bool
    {
        return $this->getAttribute('company_id') === null;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
