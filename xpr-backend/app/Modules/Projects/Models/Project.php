<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Modules\Partners\Models\Partner;
use App\Modules\Projects\Enums\ProjectStatus;
use App\Modules\Services\Models\Service;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Projet suivi pour un client : l'avancement d'une mission et les livrables
 * qu'on lui a remis.
 *
 * @property string $id
 * @property string $company_id
 * @property string $partner_id
 * @property string|null $service_id
 * @property string $title
 * @property ProjectStatus $status
 * @property int $progress_percentage
 * @property string|null $description
 * @property string|null $created_by
 */
final class Project extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'partner_id',
        'service_id',
        'title',
        'status',
        'progress_percentage',
        'description',
        'created_by',
    ];

    /**
     * Client pour qui le projet est mené.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Service dont relève le projet — la nature de la mission.
     *
     * NULLABLE, et ce sera le cas de tout projet créé avant l'ouverture du
     * référentiel : le classement est facultatif. La relation peut aussi être
     * chargée ET nulle, `Service` portant un soft delete — un service retiré du
     * référentiel ne fait pas disparaître les projets qu'il classait.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Livrables remis, du plus RÉCENT au plus ancien.
     *
     * L'ordre est porté par la relation et non par chaque appelant : c'est
     * toujours la dernière remise qu'on vient chercher, et le laisser à
     * l'appelant garantirait qu'un écran l'oublie.
     *
     * @return HasMany<Deliverable, $this>
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class)
            ->orderByDesc('delivery_date')
            ->orderByDesc('created_at');
    }

    /**
     * Recherche libre sur le titre du projet.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where('title', 'ILIKE', "%{$escaped}%");
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'progress_percentage' => 'integer',
        ];
    }
}
