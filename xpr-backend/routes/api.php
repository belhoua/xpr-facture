<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Ce fichier reste volontairement vide : le découpage est modulaire (§15).
| Chaque domaine déclare ses routes dans `app/Modules/<Domaine>/routes.php`,
| chargées par son ServiceProvider enregistré dans `bootstrap/providers.php`.
|
| Routes actuellement exposées sous /api/v1 :
|   Authentication  auth/*                        (register, login, me, …)
|   Tenancy         users, users/invitations
|   Invoices        invoices
|   Cash            cash/movements, cash
|   Dashboard       dashboard/stats, dashboard
|   AdminNotes      admin-notes
|
*/
