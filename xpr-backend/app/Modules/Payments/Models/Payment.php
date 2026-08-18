<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Modules\Documents\Models\Document;
use App\Modules\Payments\Enums\CheckStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Shared\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Règlement reçu sur une facture.
 *
 * SOFT DELETE, comme les documents : un encaissement retiré a existé, et son
 * effacement dur ferait disparaître la trace d'une écriture qui a compté dans
 * un cumul — y compris, peut-être, dans une déclaration déjà déposée.
 *
 * @property string $id
 * @property string $company_id
 * @property string $invoice_id
 * @property int $amount_cents
 * @property string $currency
 * @property Carbon $paid_on
 * @property PaymentMethod $method
 * @property string|null $reference
 * @property string|null $notes
 * @property string|null $check_number
 * @property Carbon|null $bank_deposit_date
 * @property Carbon|null $received_date
 * @property CheckStatus|null $check_status
 * @property string|null $scan_path
 * @property string|null $scan_name
 * @property string|null $created_by
 */
final class Payment extends Model
{
    use BelongsToCompany;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'amount_cents',
        'currency',
        'paid_on',
        'method',
        'reference',
        'notes',
        'check_number',
        'bank_deposit_date',
        'received_date',
        'check_status',
        'scan_path',
        'scan_name',
        'created_by',
    ];

    /**
     * Facture réglée.
     *
     * @return BelongsTo<Document, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'invoice_id');
    }

    /** Le titre porte-t-il un scan consultable ? */
    public function hasScan(): bool
    {
        return $this->scan_path !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_on' => 'date',
            'bank_deposit_date' => 'date',
            'received_date' => 'date',
            'method' => PaymentMethod::class,
            'check_status' => CheckStatus::class,
        ];
    }
}
