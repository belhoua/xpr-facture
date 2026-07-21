<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotence des créations sensibles — facture, paiement (§6).
 *
 * La première requête portant un en-tête `Idempotency-Key` stocke sa réponse ;
 * un rejeu avec la même clé la restitue sans ré-exécuter. Sans ce garde-fou, un
 * double-clic ou une reprise réseau émet deux factures, donc consomme deux
 * numéros de séquence — impossible à corriger autrement que par un avoir.
 *
 * `request_hash` distingue le rejeu légitime (même corps → on rejoue la
 * réponse) de la réutilisation abusive d'une clé avec un corps différent, qui
 * doit être refusée en 422 plutôt que de rendre une réponse sans rapport.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idem_key', 100)->comment('en-tête Idempotency-Key');
            $table->string('endpoint', 150)->comment('POST /api/v1/invoices');
            $table->char('request_hash', 64);
            $table->smallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            // Purgées par un job au-delà de cette date : la table ne doit pas
            // croître indéfiniment pour un usage à durée de vie courte.
            $table->timestampTz('expires_at');

            $table->unique(['company_id', 'endpoint', 'idem_key'], 'idempotency_unique');
            $table->index('expires_at', 'idempotency_expiry_idx');
        });

        RlsMigration::apply('idempotency_keys');
    }

    public function down(): void
    {
        RlsMigration::drop('idempotency_keys');
        Schema::dropIfExists('idempotency_keys');
    }
};
