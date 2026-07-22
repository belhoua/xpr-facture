<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Partners\Models\Partner;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Document commercial : devis, facture, avoir et les types dérivés — tous dans
 * la même table, discriminés par `type` (arbitrage du 2026-07-21, cf. la
 * migration `rename_invoices_to_documents`).
 *
 * @property string $id
 * @property string $company_id
 * @property DocumentType $type
 * @property string|null $partner_id
 * @property string|null $parent_document_id
 * @property string|null $number
 * @property string $client_name
 * @property string|null $client_ice
 * @property string|null $client_address
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property DocumentStatus $status
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string $currency
 * @property string|null $notes
 * @property string|null $terms
 */
final class Document extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'type',
        'partner_id',
        'parent_document_id',
        'number',
        'client_name',
        'client_ice',
        'client_address',
        'issued_at',
        'due_at',
        'status',
        'subtotal_cents',
        'discount_cents',
        'tax_cents',
        'total_cents',
        'currency',
        'notes',
        'terms',
    ];

    /**
     * Lignes du document, DANS L'ORDRE DE SAISIE.
     *
     * L'`orderBy` est porté par la relation et non laissé aux appelants :
     * sans lui, PostgreSQL rend les lignes dans l'ordre qui l'arrange, et le
     * PDF ne reproduirait pas ce que l'utilisateur a composé.
     *
     * @return HasMany<DocumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DocumentItem::class)->orderBy('position');
    }

    /**
     * Tiers rattaché. L'affichage du document n'en dépend PAS : `client_name`
     * porte le nom figé à l'émission (§3). Cette relation sert aux agrégats.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Document dont celui-ci découle : le devis pour une facture convertie,
     * la facture pour un avoir.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    /**
     * Documents issus de celui-ci — la facture née du devis, les avoirs émis
     * sur la facture.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, DocumentType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /**
     * Recherche libre sur le numéro et le nom du client figé sur le document.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = addcslashes($term, '%_\\');

        return $query->where(function (Builder $inner) use ($escaped): void {
            $inner
                ->where('client_name', 'ILIKE', "%{$escaped}%")
                ->orWhere('number', 'ILIKE', "%{$escaped}%");
        });
    }

    /** Un document sans numéro n'a jamais été émis : c'est encore un brouillon. */
    public function isIssued(): bool
    {
        return $this->number !== null;
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'status' => DocumentStatus::class,
            'issued_at' => 'date',
            'due_at' => 'date',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
        ];
    }
}
