<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Modules\Accounting\Enums\DocumentType;
use App\Modules\Documents\Enums\DocumentStatus;
use App\Modules\Partners\Models\Partner;
use App\Modules\Payments\Models\Payment;
use App\Modules\Projects\Models\Project;
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
 * @property string|null $project_id
 * @property string|null $parent_document_id
 * @property string|null $number
 * @property string $client_name
 * @property string|null $client_ice
 * @property string|null $client_address
 * @property string|null $subject
 * @property string|null $issue_city ville d'établissement portée sur le document
 * @property int $paid_cents
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

    /**
     * Nom de la fiche client CRÉÉE en enregistrant ce document, le cas échéant.
     *
     * Hors base, et volontairement : ce n'est pas une propriété du document
     * mais un fait sur l'écriture qui vient d'avoir lieu — l'interface doit
     * pouvoir annoncer qu'une fiche est née d'un nom saisi librement et reste à
     * compléter. Une colonne le figerait pour toujours ; un attribut Eloquent
     * serait candidat à la persistance au prochain `save()`.
     *
     * `null` sur toute lecture, et sur toute écriture qui a RETROUVÉ un tiers
     * existant plutôt que d'en créer un.
     */
    public ?string $autoCreatedPartnerName = null;

    protected $fillable = [
        'type',
        'partner_id',
        'project_id',
        'parent_document_id',
        'number',
        'client_name',
        'client_ice',
        'client_address',
        'subject',
        'issue_city',
        'issued_at',
        'due_at',
        'status',
        'subtotal_cents',
        'discount_cents',
        'tax_cents',
        'total_cents',
        'paid_cents',
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
     * Projet que cette pièce facture, quand elle en relève.
     *
     * NULLABLE, et ce sera le cas le plus fréquent : une facture de fournitures
     * ou un devis ponctuel ne relèvent d'aucun projet. La relation peut aussi
     * être chargée ET nulle — `Project` porte un soft delete, la pièce fiscale
     * lui survit.
     *
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Document dont celui-ci découle : le devis pour une facture convertie.
     *
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_document_id');
    }

    /**
     * Documents issus de celui-ci — la facture née du devis.
     *
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_document_id');
    }

    /**
     * Règlements reçus sur ce document.
     *
     * Déclarée sur `Document` et non sur la seule facture : la table n'a qu'un
     * type de document, et c'est le service d'écriture qui tient la règle « on
     * ne règle qu'une facture émise ». Une relation qui n'existerait que pour
     * un `type` ne serait de toute façon pas exprimable en Eloquent.
     *
     * Les règlements retirés en sont exclus : `Payment` est en soft delete, et
     * la relation hérite de son global scope. Un encaissement supprimé demeure
     * en base pour la trace, il ne doit plus peser dans aucun cumul.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
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
     * Recherche libre sur le numéro, le nom du client figé et l'objet.
     *
     * L'objet y figure parce que c'est le seul texte discriminant d'une
     * situation : « Situation du mois d'octobre » se cherche par « octobre »,
     * pas par un numéro qu'on ne retient pas.
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
                ->orWhere('number', 'ILIKE', "%{$escaped}%")
                ->orWhere('subject', 'ILIKE', "%{$escaped}%");
        });
    }

    /** Un document sans numéro n'a jamais été émis : c'est encore un brouillon. */
    public function isIssued(): bool
    {
        return $this->number !== null;
    }

    /**
     * Reste à payer. Jamais négatif : la contrainte
     * `documents_paid_not_above_total_check` l'interdit en base, le `max()` ne
     * fait que garantir la cohérence de l'affichage si la donnée venait à être
     * lue pendant une écriture concurrente.
     */
    public function remainingCents(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    /**
     * État de règlement DÉDUIT du montant encaissé — jamais stocké en double.
     *
     * C'est cette méthode qui fait autorité sur les trois badges de l'écran
     * Situations (non payé / partiel / payé). Un document non émis n'a pas
     * d'état de règlement : il n'y a pas encore de créance.
     */
    public function settlementStatus(): DocumentStatus
    {
        if (! $this->isIssued()) {
            return DocumentStatus::Draft;
        }

        // Un total à zéro avec zéro encaissé n'est pas « payé » : il n'y a
        // simplement rien à régler. On le laisse « envoyé » plutôt que
        // d'annoncer un règlement qui n'a pas eu lieu.
        if ($this->total_cents > 0 && $this->paid_cents >= $this->total_cents) {
            return DocumentStatus::Paid;
        }

        return $this->paid_cents > 0 ? DocumentStatus::Partial : DocumentStatus::Sent;
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
            'paid_cents' => 'integer',
        ];
    }
}
