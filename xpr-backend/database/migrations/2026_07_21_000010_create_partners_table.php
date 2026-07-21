<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Répertoire des tiers : clients ET fournisseurs dans une seule table,
 * discriminés par `type`.
 *
 * Pourquoi une table unique : les deux portent exactement la même identité
 * légale marocaine (ICE, IF, RC, patente), la même adresse et les mêmes
 * coordonnées ; seul le sens de la relation commerciale change. Beaucoup de
 * tiers sont d'ailleurs les DEUX à la fois — un fournisseur à qui l'on vend
 * aussi. Deux tables auraient imposé de le saisir deux fois, puis de tenir deux
 * fiches à jour, avec des ICE qui divergent.
 *
 * `client_name` en texte libre sur `invoices` est ce que cette table remplace :
 * sans identité stable, aucun KPI « clients actifs » ni aucune situation de
 * compte n'est calculable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            $table->string('type', 10)->default('client');
            // Référence interne saisie par la société (CL-001, F-2026-12…).
            // Libre : chaque cabinet a sa codification, on ne l'impose pas.
            $table->string('code', 30)->nullable();

            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('legal_form', 20)->nullable()->comment('NULL pour un particulier');

            // Identité fiscale — nullable : un particulier n'a ni ICE ni IF, et
            // la fiche doit pouvoir être créée avant d'avoir obtenu ces pièces.
            $table->char('ice', 15)->nullable();
            $table->string('if_number', 20)->nullable();
            $table->string('rc_number', 20)->nullable();
            $table->string('rc_city', 100)->nullable();
            $table->string('patente', 20)->nullable();

            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country', 2)->default('MA');

            $table->char('currency', 3)->default('MAD');
            // Délai de règlement convenu, en jours : sert à calculer l'échéance
            // par défaut d'un document. 0 = comptant.
            $table->smallInteger('payment_terms_days')->default(30);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency')->references('code')->on('currencies');
            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'legal_name']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE partners
              ADD CONSTRAINT partners_type_check
              CHECK (type IN ('client','supplier','both'))
        SQL);

        // Même règle que sur `companies` : l'ICE marocain fait 15 chiffres.
        DB::statement("ALTER TABLE partners ADD CONSTRAINT partners_ice_format_check CHECK (ice ~ '^[0-9]{15}$')");

        DB::statement(<<<'SQL'
            ALTER TABLE partners
              ADD CONSTRAINT partners_payment_terms_check
              CHECK (payment_terms_days >= 0 AND payment_terms_days <= 365)
        SQL);

        // Un ICE identifie une entreprise : deux fiches ne peuvent pas le
        // partager au sein d'une société. Index PARTIEL — les NULL (particuliers)
        // doivent rester multiples, et une fiche archivée ne bloque pas son ICE.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX partners_company_ice_unique
              ON partners (company_id, ice)
              WHERE ice IS NOT NULL AND deleted_at IS NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX partners_company_code_unique
              ON partners (company_id, code)
              WHERE code IS NOT NULL AND deleted_at IS NULL
        SQL);

        RlsMigration::apply('partners');
    }

    public function down(): void
    {
        RlsMigration::drop('partners');
        Schema::dropIfExists('partners');
    }
};
