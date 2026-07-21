<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fichiers : logos, pièces jointes, PDF archivés (P0-13).
 *
 * `path` est une clé d'objet ALÉATOIRE dans MinIO/S3, hors webroot ;
 * `original_name` n'est qu'un libellé d'affichage — servir un fichier sous le
 * nom fourni par l'utilisateur ouvre la porte au path traversal et à
 * l'exécution de contenu (§10).
 *
 * `mime_type` est le type RÉEL détecté à l'upload, jamais celui déclaré par le
 * client. `checksum_sha256` sert à la déduplication et à la détection
 * d'altération.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk', 30)->default('s3');
            $table->string('path', 500)->comment('clé objet aléatoire, jamais le nom fourni');
            $table->string('original_name', 255);
            $table->string('mime_type', 120)->comment('MIME réel détecté, pas déclaré');
            $table->bigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            // Rattachement polymorphe optionnel : facture → PDF archivé, etc.
            $table->string('attachable_type', 120)->nullable();
            $table->uuid('attachable_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->softDeletesTz();

            $table->index(['company_id', 'attachable_type', 'attachable_id'], 'files_attachable_idx');
        });

        DB::statement('ALTER TABLE files ADD CONSTRAINT files_size_check CHECK (size_bytes >= 0)');

        // Le logo de la société est un fichier comme un autre : la colonne
        // n'existait pas encore à la création de `companies` (dépendance
        // circulaire), elle est ajoutée ici.
        Schema::table('companies', function (Blueprint $table): void {
            $table->foreignUuid('logo_file_id')->nullable()->constrained('files')->nullOnDelete();
        });

        RlsMigration::apply('files');
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('logo_file_id');
        });

        RlsMigration::drop('files');
        Schema::dropIfExists('files');
    }
};
