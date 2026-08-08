<?php

declare(strict_types=1);

use App\Modules\Shared\Database\RlsMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contrat de convention de contrôle et suivi — la pièce que BCAT signe avec le
 * maître d'ouvrage avant d'ouvrir un chantier (modèle client :
 * `docs/Contrat de convention modele.docx`).
 *
 * TABLE DÉDIÉE, et non un neuvième `DocumentType` dans `documents` — l'écart au
 * modèle « une table, un discriminant » (arbitrage du 2026-07-21) est assumé,
 * pour trois raisons qui tiennent toutes au fait qu'une convention n'est PAS
 * une pièce commerciale :
 *
 *  1. **Pas de lignes, pas de TVA.** Le moteur de `documents` s'articule autour
 *     de `document_items` et d'un récapitulatif de taxe par taux. Une convention
 *     porte un forfait TTC unique et un échéancier en pourcentages — la moitié
 *     des colonnes de `documents` resterait vide et l'autre moitié mentirait.
 *  2. **Pas de numérotation fiscale.** Le n° de dossier (`0003439/AK/26`) est
 *     attribué par l'organisme instructeur, pas par `sequences` : le faire
 *     passer par `DocumentNumberService` consommerait un numéro de séquence
 *     pour une pièce que la DGI n'attend pas.
 *  3. **Champs propres.** Le maître d'ouvrage, le titre foncier, les lots
 *     contrôlés, le délai d'exécution n'ont aucun équivalent sur un devis, et
 *     les ajouter à `documents` alourdirait les huit types existants pour un
 *     seul.
 *
 * Le lien avec le devis ou la facture d'origine reste explicite
 * (`source_document_id`) : c'est lui qui porte la traçabilité « d'où viennent
 * ces honoraires », et il survit à l'archivage du document source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conventions', function (Blueprint $table): void {
            $table->uuid('id')->primary()->default(DB::raw('uuid_generate_v7()'));
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();

            // Devis ou facture d'origine. `nullOnDelete` et non `cascade` : une
            // convention SIGNÉE ne disparaît pas parce que le devis qui l'a
            // engendrée a été supprimé — elle vaut par elle-même.
            $table->foreignUuid('source_document_id')->nullable()
                ->constrained('documents')->nullOnDelete();
            $table->foreignUuid('partner_id')->nullable()
                ->constrained('partners')->nullOnDelete();

            // N° de dossier tel que délivré par l'organisme (« 0003439/AK/26 ») :
            // SAISI, jamais généré. Nullable — la convention se rédige avant
            // d'obtenir le numéro.
            $table->string('dossier_number', 40)->nullable();
            $table->string('status', 20)->default('draft');

            $table->string('issue_city', 100)->nullable();
            $table->date('issued_at')->nullable();

            // Maître d'ouvrage : identité FIGÉE à la rédaction, comme
            // `client_name` sur un document. La convention est un contrat signé,
            // elle ne doit pas suivre un renommage de la fiche tiers.
            $table->string('owner_name');
            $table->char('owner_ice', 15)->nullable();
            $table->string('owner_rc', 40)->nullable();
            $table->text('owner_address')->nullable();

            $table->text('project_description');
            $table->text('project_address')->nullable();
            // Titre foncier (« TF : 138618/04 ») : identifie la parcelle, c'est
            // la seule référence opposable du projet.
            $table->string('project_title_deed', 60)->nullable();

            // Lots contrôlés (article 1) : liste ordonnée de libellés. jsonb et
            // non une table fille — ce sont des lignes de TEXTE recopiées dans
            // le contrat imprimé, sans identité propre, sans jointure, sans
            // agrégat. Une table de plus pour un tableau de chaînes ne se
            // justifierait qu'au jour où un lot devient une entité (chiffrage,
            // intervenant, planning) ; ce jour-là elle sera créée pour de vraies
            // raisons.
            $table->jsonb('lots')->default(DB::raw("'[]'::jsonb"));
            $table->string('execution_delay', 255)->nullable();

            // Honoraires forfaitaires TTC, en centimes (§7). Repris du devis ou
            // de la facture au transfert, puis modifiables : la négociation
            // finale a lieu au moment de la signature.
            $table->bigInteger('total_cents')->default(0);
            $table->char('currency', 3)->default('MAD');

            // Échéancier de l'article 10. En POURCENTAGES et non en montants :
            // c'est ainsi que le contrat est rédigé (« 25 % du montant total »),
            // et les montants s'en déduisent sans jamais pouvoir diverger du
            // total. Les valeurs par défaut sont celles du modèle client.
            $table->smallInteger('advance_percent')->default(25);
            $table->smallInteger('visa_percent')->default(25);
            $table->smallInteger('completion_percent')->default(50);

            $table->text('notes')->nullable();

            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign('currency')->references('code')->on('currencies');
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'issued_at']);
            $table->index(['company_id', 'source_document_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE conventions
              ADD CONSTRAINT conventions_status_check
              CHECK (status IN ('draft','sent','signed','cancelled'))
        SQL);

        // Même règle que `companies` et `partners` : l'ICE marocain fait
        // 15 chiffres (§3).
        DB::statement("ALTER TABLE conventions ADD CONSTRAINT conventions_owner_ice_check CHECK (owner_ice ~ '^[0-9]{15}$')");

        DB::statement('ALTER TABLE conventions ADD CONSTRAINT conventions_total_positive_check CHECK (total_cents >= 0)');

        // L'échéancier doit couvrir EXACTEMENT le forfait : une somme à 90 %
        // laisserait 10 % des honoraires sans modalité de règlement, et le
        // contrat imprimé afficherait un échéancier incomplet sans le dire.
        DB::statement(<<<'SQL'
            ALTER TABLE conventions
              ADD CONSTRAINT conventions_schedule_check
              CHECK (
                advance_percent BETWEEN 0 AND 100
                AND visa_percent BETWEEN 0 AND 100
                AND completion_percent BETWEEN 0 AND 100
                AND advance_percent + visa_percent + completion_percent = 100
              )
        SQL);

        // Un n° de dossier identifie un dossier : deux conventions de la même
        // société ne peuvent pas le partager. Index PARTIEL — les NULL (dossier
        // pas encore déposé) doivent rester multiples, et une convention
        // archivée ne bloque pas son numéro.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX conventions_company_dossier_unique
              ON conventions (company_id, dossier_number)
              WHERE dossier_number IS NOT NULL AND deleted_at IS NULL
        SQL);

        RlsMigration::apply('conventions');
    }

    public function down(): void
    {
        RlsMigration::drop('conventions');
        Schema::dropIfExists('conventions');
    }
};
