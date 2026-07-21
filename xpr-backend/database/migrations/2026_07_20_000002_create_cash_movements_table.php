<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->date('occurred_at');
            $table->string('label');
            $table->string('method', 20);
            $table->string('register_name');
            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('MAD');
            $table->timestampsTz();

            $table->index(['company_id', 'occurred_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cash_movements
              ADD CONSTRAINT cash_movements_method_check
              CHECK (method IN ('cash','cheque','transfer','card','effect'))
        SQL);

        RlsMigration::apply('cash_movements');
    }

    public function down(): void
    {
        RlsMigration::drop('cash_movements');
        Schema::dropIfExists('cash_movements');
    }
};
