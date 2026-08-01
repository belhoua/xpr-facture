<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Web
|--------------------------------------------------------------------------
|
| Backend d'API pur : aucune page n'est servie ici. Le frontend Next.js
| (xpr-frontend) porte l'intégralité de l'interface, et joint l'API via
| son reverse-proxy (/api/*, /sanctum/*).
|
| Ce fichier reste chargé parce que `withRouting(web: ...)` monte le groupe
| de middleware `web` — dont dépendent les sessions Sanctum du SPA.
|
| La sonde de santé n'est pas ici : `withRouting(health: '/up')` l'expose
| dans bootstrap/app.php. C'est elle que Render interroge.
|
*/
