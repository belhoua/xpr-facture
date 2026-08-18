<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renseigne l'identité légale de l'exploitant (BCAT) sur les sociétés déjà
 * créées.
 *
 * ── Pourquoi une migration et pas seulement le seeder ──────────────────────
 *
 * `DemoSeeder` ne rejoue pas sur une base peuplée — il vérifie l'existence
 * avant d'insérer, pour rester rejouable sans dupliquer. La société de
 * l'exploitant, elle, a été créée par `xpr:create-admin`, qui ne demande qu'un
 * nom : elle vit donc en base SANS ICE, sans IF, sans RC ni RIB. Or ces
 * mentions sont obligatoires au pied de chaque devis et de chaque facture (§3),
 * et le pied de page les lit sur la société active — un champ vide n'imprime
 * rien du tout.
 *
 * ── Ce que cette migration NE FAIT PAS, délibérément ───────────────────────
 *
 * Elle ne remplit que les colonnes VIDES (`COALESCE`). Une valeur déjà saisie
 * depuis les paramètres est le fait d'un utilisateur qui savait ce qu'il
 * écrivait ; l'écraser depuis une migration reviendrait à décider à sa place,
 * et sur des mentions fiscales. Si une coordonnée erronée doit être corrigée,
 * cela se fait depuis l'écran des paramètres, pas ici.
 *
 * Conséquence à connaître : sur une base où l'ICE aurait déjà été saisi faux,
 * cette migration le laisse tel quel.
 *
 * ── Le ciblage ─────────────────────────────────────────────────────────────
 *
 * Par le NOM, faute de mieux : il n'existe pas de marqueur « société de
 * l'exploitant » dans le schéma (le produit est multi-société par
 * construction). Le motif est donc volontairement étroit — `BCAT` en tête de
 * raison sociale ou de nom commercial — pour ne jamais toucher la fiche d'une
 * autre entreprise, quitte à laisser un cas à traiter à la main.
 */
return new class extends Migration
{
    /** Coordonnées officielles, miroir de la première entrée de `DemoSeeder`. */
    private const IDENTITY = [
        'ice' => '002091111000017',
        'if_number' => '26066474',
        'rc_number' => '32577',
        'rc_city' => 'Oujda',
        'patente' => '10100485',
        'cnss' => '1144864',
        'address' => '8 BD Moulay Ahmed Lagrari 6ème Étage App N°25',
        // Le code postal vit dans la ville : la table n'a pas de colonne
        // dédiée, et c'est la forme usuelle des en-têtes marocains.
        'city' => 'OUJDA 60000',
        'phone' => '0536686883 / 0661940997',
        'email' => 'bcatcontrol@gmail.com',
        'bank_rib' => '011640000032210000180410',
    ];

    public function up(): void
    {
        $assignments = [];

        foreach (self::IDENTITY as $column => $value) {
            // NULLIF(...,'') : une chaîne vide vaut « non renseigné » aussi
            // sûrement qu'un NULL, et l'écran des paramètres produit l'une ou
            // l'autre selon les champs.
            $assignments[$column] = DB::raw(
                sprintf("COALESCE(NULLIF(%s, ''), %s)", $column, DB::getPdo()->quote($value)),
            );
        }

        $assignments['updated_at'] = now();

        DB::table('companies')
            ->where(function ($query): void {
                $query->where('legal_name', 'ILIKE', 'BCAT%')
                    ->orWhere('trade_name', 'ILIKE', 'BCAT%');
            })
            ->whereNull('deleted_at')
            ->update($assignments);
    }

    /**
     * `down()` volontairement vide.
     *
     * Remettre ces colonnes à NULL effacerait aussi bien les valeurs posées ici
     * que celles qu'un utilisateur aurait saisies depuis — rien ne les
     * distingue une fois écrites. Un rollback ne doit pas être plus destructeur
     * que la migration : celle-ci ne fait que COMBLER des vides.
     */
    public function down(): void {}
};
