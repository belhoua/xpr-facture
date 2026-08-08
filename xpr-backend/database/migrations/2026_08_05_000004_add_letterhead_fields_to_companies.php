<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les deux mentions de l'en-tête / pied de page d'un document commercial que
 * l'identité légale (§3) ne couvrait pas.
 *
 * `tagline` : la baseline imprimée sous la marque (« Bureau de contrôle et
 * d'assistance techniques »). Ce n'est ni la raison sociale ni le nom
 * commercial — les deux existent déjà et ne se substituent pas à elle : une
 * société peut changer de baseline sans toucher à son immatriculation.
 *
 * `bank_rib` : le RIB porté sur les documents, celui sur lequel le client
 * règle. Distinct du futur `bank_accounts` (§7, phase 2), qui sert le
 * RAPPROCHEMENT des encaissements : plusieurs comptes y cohabiteront, alors
 * qu'un document n'en affiche qu'un. Quand cette table arrivera, cette colonne
 * devient sa valeur d'affichage par défaut, pas un doublon à synchroniser.
 *
 * Aucune contrainte de format sur le RIB : 24 chiffres au Maroc, mais un
 * compte à l'étranger s'écrit en IBAN (jusqu'à 34 caractères), et un CHECK
 * refuserait une saisie parfaitement légitime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('tagline', 160)->nullable()->after('trade_name');
            $table->string('bank_rib', 34)->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn(['tagline', 'bank_rib']);
        });
    }
};
