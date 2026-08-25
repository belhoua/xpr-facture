<?php

declare(strict_types=1);

namespace App\Modules\Services\Models;

use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Ancien référentiel de classement des missions — MODÈLE DORMANT.
 *
 * Il ne classe plus rien depuis le 2026-08-26 : `projects.service_id` pointe le
 * CATALOGUE (`Catalog\Models\Product` de type « service »), la seule liste que
 * l'écran `/services` alimente. Les deux référentiels portaient le même mot et
 * ne se rejoignaient jamais — cf. la migration
 * `point_project_service_to_catalog` et `docs/modules/projects.md` §1 bis.
 *
 * La table et ses données subsistent, reprises dans le catalogue par cette
 * migration. Le modèle n'expose plus de relation `projects()` : elle
 * s'appuyait sur une clé étrangère qui ne désigne plus cette table.
 *
 * ⚠️ Ne pas rebrancher un écran ici : ce serait recréer le second référentiel.
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
