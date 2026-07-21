<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit (P0-12), APPEND-ONLY : tout changement d'état d'un document
 * y est tracé (§3) et une trace qu'on peut réécrire ne vaut rien.
 *
 * `company_id` est nullable : un échec de connexion survient avant toute
 * résolution de société. Ces lignes-là ne sont donc pas lisibles depuis
 * l'application — la timeline d'une société ne montre que ses propres
 * événements.
 *
 * Volume : candidat au partitionnement mensuel, à trancher en Phase 2 quand on
 * aura des ordres de grandeur réels plutôt que des suppositions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50)->comment('invoice.validated, auth.login, …');
            $table->string('auditable_type', 120)->nullable()->comment('FQCN du modèle');
            $table->uuid('auditable_id')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('user_agent', 500)->nullable();
            // Corrélation avec les logs applicatifs JSON (§10)
            $table->uuid('request_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'auditable_type', 'auditable_id'], 'audit_logs_subject_idx');
            $table->index(['company_id', 'created_at'], 'audit_logs_company_date');
        });

        // inet : type natif PostgreSQL, valide l'adresse et gère IPv4/IPv6.
        // Aucun équivalent dans le Blueprint Laravel, d'où le SQL brut.
        DB::statement('ALTER TABLE audit_logs ADD COLUMN ip_address inet');

        DB::statement('ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY');

        // Append-only garanti PAR LA BASE, sans dépendre d'un GRANT ni du rôle
        // employé : on ne déclare que SELECT et INSERT. UPDATE et DELETE n'ont
        // alors aucune policy permissive, donc aucune ligne n'est modifiable ni
        // supprimable — y compris par le propriétaire de la table, puisque
        // FORCE ROW LEVEL SECURITY s'applique aussi à lui.
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_read ON audit_logs
              FOR SELECT
              USING (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
        SQL);

        // L'INSERT tolère company_id NULL : les événements d'authentification
        // précèdent la résolution de la société.
        DB::statement(<<<'SQL'
            CREATE POLICY tenant_append ON audit_logs
              FOR INSERT
              WITH CHECK (
                company_id IS NULL
                OR company_id = NULLIF(current_setting('app.company_id', true), '')::uuid
              )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS tenant_append ON audit_logs');
        DB::statement('DROP POLICY IF EXISTS tenant_read ON audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
