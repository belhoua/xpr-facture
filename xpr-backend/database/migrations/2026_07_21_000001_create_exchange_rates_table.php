<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Taux de change historisés (CLAUDE.md §7). Par société : chacune saisit ou
 * importe les siens, et un document converti garde le taux du jour de son
 * émission — d'où l'historisation par `effective_date` plutôt qu'un taux courant
 * écrasé à chaque mise à jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->char('currency_code', 3);
            // 1 unité de currency_code = `rate` unités de la devise pivot de la
            // société. numeric(18,8) : exact, jamais un flottant (§7).
            $table->decimal('rate', 18, 8);
            $table->date('effective_date');
            $table->string('source', 50)->nullable()->comment('manual, bkam, …');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('currency_code')->references('code')->on('currencies');
            $table->unique(['company_id', 'currency_code', 'effective_date'], 'exchange_rates_unique');
            $table->index(['company_id', 'currency_code', 'effective_date'], 'exchange_rates_lookup_idx');
        });

        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_positive CHECK (rate > 0)');

        RlsMigration::apply('exchange_rates');
    }

    public function down(): void
    {
        RlsMigration::drop('exchange_rates');
        Schema::dropIfExists('exchange_rates');
    }
};
