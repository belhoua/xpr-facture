<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Models;

use App\Modules\Shared\Concerns\BelongsToCompany;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $number
 * @property string $client_name
 * @property Carbon|null $issued_at
 * @property Carbon|null $due_at
 * @property string $status
 * @property int $total_cents
 * @property string $currency
 */
final class Invoice extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'number',
        'client_name',
        'issued_at',
        'due_at',
        'status',
        'total_cents',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_at' => 'date',
            'total_cents' => 'integer',
        ];
    }

    /** Le modèle vit hors d'App\Models : la convention ne trouve pas la factory. */
    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
