<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le tenant : identité légale marocaine complète (CLAUDE.md §3).
 * Table globale — pas de RLS ici, l'accès passe par les Policies et le pivot
 * company_user. Pas d'unicité sur l'ICE (arbitrage cadrage : une même société
 * réelle peut exister pour elle-même et chez son cabinet comptable).
 * logo_file_id sera ajouté par le module Files (la table files n'existe pas encore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('legal_form', 20);
            $table->char('ice', 15)->nullable();
            $table->string('if_number', 20)->nullable();
            $table->string('rc_number', 20)->nullable();
            $table->string('rc_city', 100)->nullable();
            $table->string('patente', 20)->nullable();
            $table->string('cnss', 20)->nullable();
            $table->bigInteger('share_capital')->nullable()->comment('centimes, NULL pour auto-entrepreneur');
            $table->string('vat_regime', 15)->default('debit');
            $table->boolean('vat_exempt')->default(false)->comment('AE sous seuil : mention "TVA non applicable"');
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->default('MA');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->char('default_currency', 3)->default('MAD');
            $table->string('timezone', 64)->default('Africa/Casablanca');
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('default_currency')->references('code')->on('currencies');
        });

        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_ice_format_check CHECK (ice ~ '^[0-9]{15}$')");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_legal_form_check CHECK (legal_form IN ('auto_entrepreneur','sarl','sarl_au','sa','sas','snc','cooperative'))");
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_vat_regime_check CHECK (vat_regime IN ('debit','encaissement'))");
        DB::statement('ALTER TABLE companies ALTER COLUMN email TYPE citext');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
