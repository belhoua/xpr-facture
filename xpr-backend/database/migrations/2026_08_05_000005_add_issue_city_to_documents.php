<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ville d'établissement du document — le « RABAT, le 05/08/2026 » qui ouvre un
 * devis imprimé.
 *
 * Elle est portée par le DOCUMENT et non par la société : un bureau de contrôle
 * établit ses devis là où se trouve le chantier, pas à son siège. Le modèle
 * fourni par le client le montre en creux, son en-tête annonçant Rabat quand
 * son pied de page domicilie la société à Oujda.
 *
 * Nommée `issue_city` et non `city` : la table porte déjà `client_name`,
 * `client_ice` et `client_address`, et un `city` nu s'y lirait comme la ville du
 * CLIENT. Ce n'est pas la même donnée.
 *
 * Nullable, sans valeur par défaut en base : le repli d'affichage appartient à
 * la présentation (`lib/brand.ts`), pas au schéma. Écrire « RABAT » en dur ici
 * le rendrait indistinguable d'une saisie réelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('issue_city', 100)->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn('issue_city');
        });
    }
};
