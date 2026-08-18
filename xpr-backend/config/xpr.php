<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Jeu de démonstration à la création d'une société
    |--------------------------------------------------------------------------
    |
    | À l'inscription, CompanyProvisioning peuple la nouvelle société d'un jeu
    | réaliste (tiers, catalogue, factures émises, mouvements de caisse) pour
    | que les écrans ne s'ouvrent pas vides. Ce jeu passe par le moteur réel :
    | il consomme la numérotation, verrou de ligne compris, soit une centaine
    | d'allers-retours SQL DANS la requête d'inscription.
    |
    | En conteneur, avec PostgreSQL sur le même réseau, le coût est négligeable.
    | En serverless devant une base distante (Neon), chaque aller-retour paie la
    | latence réseau et l'inscription peut dépasser le `maxDuration` de la
    | fonction — l'utilisateur reçoit un timeout sur un compte pourtant créé.
    |
    | Désactiver ce jeu ne casse rien : la société est simplement vide, et
    | chaque écran affiche son état vide (CLAUDE.md §6).
    |
    | DÉSACTIVÉ PAR DÉFAUT. Le défaut d'un logiciel de facturation doit être le
    | livre vierge : ces documents sont émis par le moteur réel, donc numérotés
    | dans la séquence légale, et rien ne les distingue de vraies factures une
    | fois en base. Un exploitant qui découvre son premier écran ne doit pas
    | avoir à faire le tri entre son chiffre d'affaires et le nôtre. À n'activer
    | que sur un environnement de démonstration assumé.
    |
    */

    'demo_data_on_signup' => (bool) env('XPR_DEMO_DATA_ON_SIGNUP', false),

    /*
    |--------------------------------------------------------------------------
    | Compte d'amorçage créé par le seed
    |--------------------------------------------------------------------------
    |
    | `AdminSeeder` crée la société par défaut et son propriétaire, pour qu'un
    | `migrate:fresh --seed` livre une base sur laquelle on peut se CONNECTER.
    | Une base sans aucun compte n'est pas exploitable : le front n'a alors que
    | des 401 à afficher, ce qui se lit à l'écran comme une panne générale.
    |
    | ── Le mot de passe ───────────────────────────────────────────────────────
    |
    | Le défaut ci-dessous n'existe QUE hors production (cf. AdminSeeder, qui
    | s'abstient en production tant que XPR_ADMIN_PASSWORD n'est pas défini).
    | Un mot de passe d'amorçage publié dans un dépôt Git est une clé laissée
    | sur la porte : en développement c'est un confort, en production ce serait
    | une porte dérobée documentée, indexée et identique chez tous les
    | déploiements. D'où le refus explicite plutôt qu'un défaut « raisonnable ».
    |
    | En production, l'amorçage se fait par `php artisan xpr:create-admin`, qui
    | demande le mot de passe en saisie masquée.
    |
    */

    'admin' => [
        'email' => env('XPR_ADMIN_EMAIL', 'chakib123@bcat.com'),
        'name' => env('XPR_ADMIN_NAME', 'Chakib'),
        'company' => env('XPR_ADMIN_COMPANY', 'BCAT'),
        'legal_form' => env('XPR_ADMIN_LEGAL_FORM', 'sarl'),

        /*
         * JAMAIS de défaut ici, et jamais en dur dans le seeder : le dépôt
         * distant est PUBLIC (cf. le .gitignore de la racine). Un mot de passe
         * écrit dans un fichier versionné serait publié sur GitHub, et resterait
         * lisible dans l'historique après correction.
         *
         * Il se fournit donc par l'environnement, le temps de l'amorçage :
         *     XPR_ADMIN_PASSWORD='…' php artisan db:seed --force
         *
         * `db:seed` SANS `--class`, et c'est important : sur une base fraîche,
         * `AdminSeeder` seul échoue en violation de clé étrangère
         * (`companies.default_currency` référence `currencies`, vide à ce
         * stade). `DatabaseSeeder` enchaîne les référentiels indispensables
         * puis l'amorçage, et rien d'autre — aucune donnée de démonstration.
         *
         * Sans lui, `AdminSeeder` s'abstient en production plutôt que d'ouvrir
         * un compte propriétaire dont le mot de passe serait connu de tous.
         */
        'password' => env('XPR_ADMIN_PASSWORD'),

        /*
         * Rôle du compte d'amorçage. `owner` par défaut : c'est le seul rôle
         * qui puisse modifier l'IDENTITÉ LÉGALE de la société — ICE, IF, RC,
         * patente —, mentions obligatoires au pied de chaque facture (§3). Sur
         * une base qui ne contient qu'un compte, `admin` laisserait ces champs
         * inaccessibles à tout le monde.
         */
        'role' => env('XPR_ADMIN_ROLE', 'owner'),
    ],

];
