<?php

declare(strict_types=1);

namespace App\Modules\Cash\Models;

use App\Modules\Partners\Models\Partner;
use App\Modules\Payments\Models\Payment;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\CashMovementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $partner_id
 * @property string|null $payment_id
 * @property Carbon $occurred_at
 * @property string $label
 * @property string|null $charge
 * @property string $method
 * @property string|null $register_name
 * @property int $amount_cents
 * @property string $currency
 */
final class CashMovement extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CashMovementFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'partner_id',
        // Rattachement au règlement dont ce mouvement est la COPIE. `null` sur
        // une écriture saisie à la main, qui est le cas courant.
        'payment_id',
        'occurred_at',
        'label',
        // Nature de la dépense, sur un décaissement seulement. Texte libre :
        // voir la migration `add_charge_to_cash_movements` pour ce que ce
        // choix apporte et ce qu'il coûte.
        'charge',
        'method',
        'register_name',
        'amount_cents',
        'currency',
    ];

    /**
     * Le mouvement est-il la COPIE d'un règlement de facture ?
     *
     * Ce que cela emporte : il ne se corrige ni ne se supprime depuis l'écran
     * Caisses. Sa source de vérité est le règlement, dont la facture dérive
     * `paid_cents` et son statut ; le retoucher ici ferait diverger les deux
     * sans que rien ne le signale (cf. `CashMovementWriteService`).
     */
    public function isMirroredPayment(): bool
    {
        return $this->payment_id !== null;
    }

    /**
     * Règlement dont ce mouvement est la copie.
     *
     * `withTrashed()` : `Payment` est en soft delete, et un miroir ne survit
     * pas à son règlement — `PaymentCashMirror::forget()` l'efface. La relation
     * n'a donc normalement rien à rattraper ; elle va tout de même chercher les
     * lignes retirées, pour que le jour où une suppression contourne le
     * service, l'écran affiche la pièce d'origine au lieu d'une ligne muette.
     *
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    /**
     * Tiers concerné par le mouvement.
     *
     * NULLABLE : un décaissement n'a souvent aucun tiers de ce répertoire —
     * un loyer, un plein de carburant. La relation peut aussi être chargée ET
     * nulle, `Partner` portant un soft delete : une écriture de caisse est un
     * fait comptable, elle survit à l'archivage du tiers.
     *
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    protected function casts(): array
    {
        return [
            'occurred_at' => 'date',
            'amount_cents' => 'integer',
        ];
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): CashMovementFactory
    {
        return CashMovementFactory::new();
    }
}
