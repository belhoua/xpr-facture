<?php

declare(strict_types=1);

namespace App\Modules\Invoices\Services;

use App\Modules\Invoices\Models\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class InvoiceListService
{
    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function paginate(?string $search, ?string $status, int $page, int $perPage): LengthAwarePaginator
    {
        $query = Invoice::query()->orderByDesc('issued_at')->orderByDesc('created_at');

        if ($search !== null && $search !== '') {
            $needle = '%'.addcslashes($search, '%_\\').'%';
            $query->where(function ($builder) use ($needle): void {
                $builder
                    ->where('client_name', 'ilike', $needle)
                    ->orWhere('number', 'ilike', $needle);
            });
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate(perPage: $perPage, page: $page);
    }
}
