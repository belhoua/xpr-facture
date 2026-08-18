<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Règlements reçus sur une FACTURE.
 *
 * ── Pourquoi une table et non le seul `documents.paid_cents` ───────────────
 *
 * `paid_cents` porte le CUMUL et lui seul : il dit combien a été encaissé, pas
 * quand, ni par quel moyen, ni sous quelle référence. Un chèque remis le 3,
 * déposé le 12 et rejeté le 15 est une histoire que ce compteur ne peut pas
 * raconter — et c'est précisément celle qu'un comptable vient chercher quand
 * un client conteste. La colonne demeure : elle reste la valeur DÉRIVÉE que
 * lisent le pied de facture, le tableau de bord et le statut de règlement.
 *
 * ── Rattachement aux FACTURES seulement ────────────────────────────────────
 *
 * `invoice_id` référence `documents`, mais la contrainte métier « c'est une
 * facture » n'est pas exprimable en CHECK : elle porterait sur une autre table.
 * Elle est donc tenue par le service, qui refuse tout autre type. Le devis en
 * est exclu par nature — une proposition ne s'encaisse pas ; la situation a son
 * propre suivi d'avancement.
 *
 * ── Les colonnes de chèque et de LCN ───────────────────────────────────────
 *
 * Un chèque et une lettre de change relevé ne sont pas des paiements comme les
 * autres : entre la remise et l'encaissement effectif s'écoulent des jours, et
 * l'effet peut revenir impayé. Le numéro, les deux dates et le statut existent
 * pour cette raison. Elles restent NULL sur les autres modes plutôt que d'aller
 * dans une table séparée : cinq colonnes creuses coûtent moins qu'une jointure
 * sur chaque lecture d'historique.
 *
 * Le CHECK ne les rend obligatoires que là où elles ont un sens : un numéro de
 * chèque est exigé sur un chèque, interdit sur des espèces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // `cascadeOnDelete` : un règlement n'existe pas sans sa facture. La
            // suppression d'une facture étant un *soft delete* applicatif, la
            // cascade ne se déclenche qu'à l'effacement dur — d'où les
            // règlements qui survivent, comme les lignes, à une facture
            // archivée.
            $table->foreignUuid('invoice_id')->constrained('documents')->cascadeOnDelete();

            $table->bigInteger('amount_cents');
            $table->char('currency', 3)->default('MAD');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();

            // ── Effets bancaires (chèque, LCN) ────────────────────────────
            $table->string('check_number', 50)->nullable();
            $table->date('bank_deposit_date')->nullable();
            $table->date('received_date')->nullable();
            $table->string('check_status', 20)->nullable();
            /** Chemin sur le disque privé, jamais une URL : cf. PaymentScanController. */
            $table->string('scan_path')->nullable();
            $table->string('scan_name')->nullable();

            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz();
            $table->softDeletesTz();

            // Index composite (société, facture) : l'historique d'une facture
            // est LA lecture de cette table, et le scope tenant ajoute toujours
            // `company_id` en tête de clause (§7).
            $table->index(['company_id', 'invoice_id']);
            // Les états de règlement se recalculent aussi par date, pour les
            // rapprochements bancaires.
            $table->index(['company_id', 'paid_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT payments_method_check
              CHECK (method IN ('cash','cheque','transfer','card','lcn','deposit'))
        SQL);

        // Un règlement de zéro n'encaisse rien et fausserait l'historique ; un
        // règlement négatif serait un remboursement, qui relève d'une autre
        // pièce et n'a pas sa place ici.
        DB::statement(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT payments_amount_positive_check
              CHECK (amount_cents > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT payments_check_status_check
              CHECK (check_status IS NULL OR check_status IN ('pending','cashed','rejected'))
        SQL);

        // Le numéro et le statut n'existent QUE sur un effet bancaire. Le CHECK
        // vaut mieux qu'une règle de validation seule : il tient aussi pour les
        // seeders, les imports et la console.
        DB::statement(<<<'SQL'
            ALTER TABLE payments
              ADD CONSTRAINT payments_check_fields_check
              CHECK (
                (method IN ('cheque','lcn') AND check_number IS NOT NULL AND check_status IS NOT NULL)
                OR (
                  method NOT IN ('cheque','lcn')
                  AND check_number IS NULL
                  AND check_status IS NULL
                  AND bank_deposit_date IS NULL
                  AND received_date IS NULL
                )
              )
        SQL);

        RlsMigration::apply('payments');
    }

    public function down(): void
    {
        RlsMigration::drop('payments');
        Schema::dropIfExists('payments');
    }
};
