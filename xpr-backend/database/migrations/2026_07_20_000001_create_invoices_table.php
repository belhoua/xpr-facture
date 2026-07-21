<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Table factures « liste » — sous-ensemble du schéma Phase 1, suffisant pour
 * alimenter GET /api/v1/invoices et le dashboard en attendant le module complet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30)->nullable();
            $table->string('client_name');
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->string('status', 15);
            $table->bigInteger('total_cents');
            $table->char('currency', 3)->default('MAD');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'issued_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE invoices
              ADD CONSTRAINT invoices_status_check
              CHECK (status IN ('draft','sent','partial','paid','overdue','cancelled'))
        SQL);

        RlsMigration::apply('invoices');
    }

    public function down(): void
    {
        RlsMigration::drop('invoices');
        Schema::dropIfExists('invoices');
    }
};
