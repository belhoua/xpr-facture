<?php

declare(strict_types=1);

namespace App\Modules\Services\Models;

use App\Modules\Projects\Models\Project;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Service de la société : la nature des missions qu'elle mène.
 *
 * À NE PAS confondre avec l'article du catalogue de type « service »
 * (`Catalog\Models\Product`), qui porte un prix et se pose sur une ligne de
 * facture. Celui-ci ne sert qu'à CLASSER — il n'a ni tarif ni TVA, et n'entre
 * dans aucun calcul (cf. la migration `create_services_table`).
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property Carbon|null $deleted_at
 */
final class Service extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['name'];

    /**
     * Projets classés sous ce service.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Recherche libre sur le nom.
     *
     * ILIKE et non un index full-text : la saisie utile est partielle et en
     * cours de frappe (« cont » → « Contrôle technique »), ce que `tsvector`,
     * qui indexe des mots entiers, ne couvre pas.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where('name', 'ILIKE', "%{$escaped}%");
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }
}
