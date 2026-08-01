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
    */

    'demo_data_on_signup' => (bool) env('XPR_DEMO_DATA_ON_SIGNUP', true),

];
