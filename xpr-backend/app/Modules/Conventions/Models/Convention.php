<?php

declare(strict_types=1);

namespace App\Modules\Conventions\Models;

use App\Modules\Conventions\Enums\ConventionStatus;
use App\Modules\Documents\Models\Document;
use App\Modules\Partners\Models\Partner;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Contrat de convention de contrôle et suivi.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $source_document_id
 * @property string|null $partner_id
 * @property string|null $dossier_number
 * @property ConventionStatus $status
 * @property string|null $issue_city
 * @property Carbon|null $issued_at
 * @property string $owner_name
 * @property string|null $owner_ice
 * @property string|null $owner_rc
 * @property string|null $owner_address
 * @property string $project_description
 * @property string|null $project_address
 * @property string|null $project_title_deed
 * @property list<string> $lots
 * @property string|null $execution_delay
 * @property int $total_cents
 * @property string $currency
 * @property int $advance_percent
 * @property int $visa_percent
 * @property int $completion_percent
 * @property string|null $notes
 */
final class Convention extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'source_document_id',
        'partner_id',
        'dossier_number',
        'status',
        'issue_city',
        'issued_at',
        'owner_name',
        'owner_ice',
        'owner_rc',
        'owner_address',
        'project_description',
        'project_address',
        'project_title_deed',
        'lots',
        'execution_delay',
        'total_cents',
        'currency',
        'advance_percent',
        'visa_percent',
        'completion_percent',
        'notes',
    ];

    /**
     * Dépôts de dossier, du plus RÉCENT au plus ancien.
     *
     * L'ordre est porté par la relation : la question posée devant cet écran est
     * toujours « où en est-on », pas « par quoi a-t-on commencé ».
     *
     * @return HasMany<FileDeposit, $this>
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(FileDeposit::class)->orderByDesc('deposited_at');
    }

    /**
     * Devis ou facture dont les honoraires sont issus. Nullable : une convention
     * peut être rédigée de zéro.
     *
     * @return BelongsTo<Document, $this>
     */
    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'source_document_id');
    }

    /**
     * Tiers rattaché. L'IMPRESSION n'en dépend pas — `owner_name` porte
     * l'identité figée à la rédaction, comme `client_name` sur un document.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Recherche libre sur le n° de dossier, le maître d'ouvrage et le projet.
     *
     * Le PROJET y figure et ce n'est pas accessoire : une convention se retrouve
     * par « polyclinique » ou par « Amizmiz » bien plus souvent que par un
     * numéro de dossier que personne ne retient.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('dossier_number', 'ILIKE', "%{$escaped}%")
                ->orWhere('owner_name', 'ILIKE', "%{$escaped}%")
                ->orWhere('project_description', 'ILIKE', "%{$escaped}%")
                ->orWhere('project_title_deed', 'ILIKE', "%{$escaped}%");
        });
    }

    /**
     * Échéancier de l'article 10, en CENTIMES.
     *
     * Calculé et non stocké : les pourcentages font foi, et deux
     * représentations du même échéancier finiraient par diverger. Le solde
     * absorbe l'arrondi — trois quarts d'un montant impair ne tombent pas juste,
     * et c'est la dernière échéance qui doit rattraper le centime, sinon la
     * somme des trois ne ferait pas le total dû.
     *
     * @return array{advance: int, visa: int, completion: int}
     */
    public function instalments(): array
    {
        $advance = intdiv($this->total_cents * $this->advance_percent, 100);
        $visa = intdiv($this->total_cents * $this->visa_percent, 100);

        return [
            'advance' => $advance,
            'visa' => $visa,
            'completion' => $this->total_cents - $advance - $visa,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConventionStatus::class,
            'issued_at' => 'date',
            'lots' => 'array',
            'total_cents' => 'integer',
            'advance_percent' => 'integer',
            'visa_percent' => 'integer',
            'completion_percent' => 'integer',
        ];
    }
}
