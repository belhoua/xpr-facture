<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notes/tickets adressés par une société aux administrateurs de la plateforme.
 *
 * Table TENANT : une société ne voit que ses propres notes. `status` est piloté
 * par le support (côté plateforme), jamais par le client — d'où l'absence de
 * `status` dans le FormRequest de création.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Auteur de la note. nullOnDelete : la note survit au départ de son
            // auteur — c'est une trace de support, pas une donnée personnelle.
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('subject', 150);
            $table->text('body');
            $table->string('priority', 10)->default('normal');
            $table->string('status', 10)->default('open');
            $table->timestampsTz();
            $table->softDeletesTz();

            // Les écrans listent par société, du plus récent au plus ancien,
            // avec un filtre par statut.
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
        });

        // Enums adossés à des CHECK : mêmes valeurs que NOTE_PRIORITIES et le
        // statut côté front (features/admin-notes/schemas/note.ts).
        DB::statement(<<<'SQL'
            ALTER TABLE admin_notes
              ADD CONSTRAINT admin_notes_priority_check
              CHECK (priority IN ('low','normal','high'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE admin_notes
              ADD CONSTRAINT admin_notes_status_check
              CHECK (status IN ('open','answered','closed'))
        SQL);

        RlsMigration::apply('admin_notes');
    }

    public function down(): void
    {
        RlsMigration::drop('admin_notes');
        Schema::dropIfExists('admin_notes');
    }
};
