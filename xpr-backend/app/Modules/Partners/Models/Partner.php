<?php

declare(strict_types=1);

namespace App\Modules\Partners\Models;

use App\Modules\Partners\Enums\PartnerType;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tiers : client, fournisseur, ou les deux.
 *
 * @property string $id
 * @property string $company_id
 * @property PartnerType $type
 * @property string|null $code
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string|null $ice
 * @property string|null $email
 * @property string $currency
 * @property int $payment_terms_days
 * @property bool $is_active
 * @property Carbon|null $deleted_at
 */
final class Partner extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'code',
        'legal_name',
        'trade_name',
        'legal_form',
        'ice',
        'if_number',
        'rc_number',
        'rc_city',
        'patente',
        'contact_name',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'currency',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    /**
     * Filtre par rôle commercial. `both` doit remonter dans les DEUX listes :
     * un simple `where('type', 'client')` masquerait la moitié du répertoire.
     *
     * `intermediary` ne remonte QUE sous son propre filtre : ce n'est pas un
     * sens de facturation mais un rôle, et le faire entrer dans les clients ou
     * les fournisseurs le proposerait à des écrans qui ne l'attendent pas
     * (cf. PartnerType).
     *
     * Le `match` est exhaustif et sans branche par défaut : ajouter un cas à
     * l'enum sans décider ici de sa place casse la compilation plutôt que de le
     * ranger silencieusement au mauvais endroit.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, PartnerType $type): Builder
    {
        return match ($type) {
            PartnerType::Client => $query->whereIn('type', [PartnerType::Client->value, PartnerType::Both->value]),
            PartnerType::Supplier => $query->whereIn('type', [PartnerType::Supplier->value, PartnerType::Both->value]),
            PartnerType::Both => $query->where('type', PartnerType::Both->value),
            PartnerType::Intermediary => $query->where('type', PartnerType::Intermediary->value),
        };
    }

    /**
     * Recherche libre sur le nom, l'enseigne, le code et l'ICE.
     *
     * ILIKE et non un index full-text : la recherche utile ici est une saisie
     * partielle en cours de frappe (« atl » → « Atlas »), ce que tsvector, qui
     * indexe des mots entiers, ne couvre pas. Un index trigram (pg_trgm) sera
     * ajouté quand le volume le justifiera — sur quelques milliers de fiches
     * par société, le scan filtré par company_id reste immédiat.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('legal_name', 'ILIKE', "%{$escaped}%")
                ->orWhere('trade_name', 'ILIKE', "%{$escaped}%")
                ->orWhere('code', 'ILIKE', "%{$escaped}%")
                ->orWhere('ice', 'ILIKE', "%{$escaped}%");
        });
    }

    /** Nom d'affichage : l'enseigne si elle existe, la raison sociale sinon. */
    public function displayName(): string
    {
        return $this->trade_name ?? $this->legal_name;
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): PartnerFactory
    {
        return PartnerFactory::new();
    }

    protected function casts(): array
    {
        return [
            'type' => PartnerType::class,
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
