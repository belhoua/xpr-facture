<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pivot d'appartenance utilisateur ↔ société, avec RLS SPÉCIFIQUE.
 *
 * La policy standard (company_id = société active) créerait un cercle vicieux :
 * pour activer une société il faut lire les appartenances de l'utilisateur…
 * qui seraient invisibles sans société active. La lecture est donc aussi
 * autorisée par app.user_id (posé dès l'authentification, avant résolution).
 * L'écriture reste strictement scopée à la société active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('joined_at')->nullable()->comment('NULL = invitation en attente');
            $table->timestampsTz();

            $table->unique(['company_id', 'user_id']);
            $table->index('user_id');
        });

        DB::statement('ALTER TABLE company_user ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE company_user FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY membership_visibility ON company_user
              USING (
                user_id = NULLIF(current_setting('app.user_id', true), '')::uuid
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid
              )
              WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
