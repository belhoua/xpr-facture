<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remplace la migration users par défaut de Laravel : uuid v7, e-mail citext
 * (insensible à la casse), locale, société par défaut. Table globale (un
 * compte peut appartenir à N sociétés) — l'appartenance vit dans company_user.
 * password_reset_tokens et sessions suivent le squelette Laravel, adaptés uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->string('name', 150);
            $table->string('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('locale', 5)->default('fr');
            $table->uuid('default_company_id')->nullable()->comment('préférence, revalidée à chaque résolution');
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('default_company_id')->references('id')->on('companies')->nullOnDelete();
        });

        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_locale_check CHECK (locale IN ('fr','ar','en'))");
        // Unicité parmi les comptes actifs : un e-mail supprimé (soft delete)
        // peut se réinscrire — index partiel, impossible via Schema builder.
        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');

        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });
        DB::statement('ALTER TABLE password_reset_tokens ALTER COLUMN email TYPE citext');

        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
