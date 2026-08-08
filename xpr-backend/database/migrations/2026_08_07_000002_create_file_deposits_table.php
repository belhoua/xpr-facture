<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dépôt de dossier : le suivi administratif d'une convention auprès d'un
 * organisme instructeur (commune, agence urbaine, protection civile…).
 *
 * Une table à part et non des colonnes sur `conventions`, parce qu'un même
 * dossier est déposé PLUSIEURS FOIS et à plusieurs guichets : un rejet de la
 * commune suivi d'un nouveau dépôt, un dossier déposé en parallèle à l'agence
 * urbaine. Aplatir cela en trois colonnes n'aurait gardé que le dernier dépôt et
 * effacé l'historique — précisément ce qu'on vient consulter quand un client
 * demande où en est son dossier.
 *
 * `company_id` y figure malgré la remontée possible par `convention_id` : le
 * global scope `BelongsToCompany` et la policy RLS filtrent sur cette colonne,
 * et une jointure ne saurait pas s'y substituer (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_deposits', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Cascade, contrairement au lien convention → document : un dépôt
            // n'a aucun sens hors de la convention qu'il instruit.
            $table->foreignUuid('convention_id')->constrained('conventions')->cascadeOnDelete();

            // Référence du dépôt (« 0003439/AK/26 ») : celle du récépissé remis
            // au guichet. Reprise du n° de dossier de la convention au premier
            // dépôt, mais SAISIE — un second dépôt en reçoit une autre.
            $table->string('reference', 40);
            $table->date('deposited_at');
            $table->string('organisation');

            $table->string('status', 20)->default('deposited');
            // Date de la décision (validation ou rejet). Nullable tant que le
            // dossier est en cours : c'est elle qui distingue « déposé le 3,
            // toujours sans réponse » de « déposé le 3, validé le 21 ».
            $table->date('decided_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'convention_id']);
            $table->index(['company_id', 'deposited_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE file_deposits
              ADD CONSTRAINT file_deposits_status_check
              CHECK (status IN ('deposited','in_progress','validated','rejected'))
        SQL);

        // Une décision ne peut pas précéder le dépôt qu'elle tranche.
        DB::statement(<<<'SQL'
            ALTER TABLE file_deposits
              ADD CONSTRAINT file_deposits_decided_after_check
              CHECK (decided_at IS NULL OR decided_at >= deposited_at)
        SQL);

        RlsMigration::apply('file_deposits');
    }

    public function down(): void
    {
        RlsMigration::drop('file_deposits');
        Schema::dropIfExists('file_deposits');
    }
};
