# Déploiement serverless (Vercel + Neon)

Complément opérationnel de `api/index.php` et `.vercelignore`, qui traitent le
paquet en lecture seule. Ce document traite ce que le code ne peut pas décider
seul : les variables d'environnement, et les hypothèses d'exécution que le
serverless invalide.

Deux projets Vercel distincts :

| Projet | Racine | Rôle |
|---|---|---|
| Frontend | `xpr-frontend/` | Next.js. Relaie `/api/*` et `/sanctum/*` vers le backend (`next.config.ts`). |
| Backend | `xpr-backend/` | Laravel, point d'entrée `api/index.php`. |

Le navigateur n'appelle **que** l'origine du frontend : Next fait proxy. Tout
est donc en même origine côté navigateur — pas de CORS, et le cookie de session
posé par Laravel est attribué au domaine du frontend.

---

## 1. Variables du projet backend

`SANCTUM_STATEFUL_DOMAINS` et `FRONTEND_URL` sont les deux qu'on oublie, et les
deux qui cassent l'authentification (cf. §3.1).

```dotenv
APP_NAME="XPR Facture"
APP_ENV=production
APP_KEY=base64:…              # OBLIGATOIRE — `php artisan key:generate --show`
APP_DEBUG=false               # true le temps d'un diagnostic, jamais en régime
APP_URL=https://<backend>.vercel.app

APP_LOCALE=fr
APP_FALLBACK_LOCALE=en

# Le domaine du FRONTEND, sans schéma, port compris s'il y en a un.
SANCTUM_STATEFUL_DOMAINS=<frontend>.vercel.app
FRONTEND_URL=https://<frontend>.vercel.app

# --- Base Neon ---
DB_CONNECTION=pgsql
DB_HOST=ep-…….<region>.aws.neon.tech
DB_PORT=5432
DB_DATABASE=neondb
DB_USERNAME=neondb_owner
DB_PASSWORD=…
DB_SSLMODE=require

# --- Pilotes : rien qui suppose un disque ou un processus persistant ---
SESSION_DRIVER=database       # jamais file : /tmp meurt avec l'instance
SESSION_LIFETIME=120
SESSION_DOMAIN=null
CACHE_STORE=database          # jamais file, pour la même raison
QUEUE_CONNECTION=sync         # aucun worker ne tourne : voir §3.3
FILESYSTEM_DISK=s3            # local = /tmp, éphémère
LOG_CHANNEL=stderr            # voir §2
LOG_LEVEL=info

# Inscription : coupe le jeu de démonstration du chemin critique (§3.4)
XPR_DEMO_DATA_ON_SIGNUP=false

# --- Mail : un transport joignable depuis la fonction (API HTTPS de préférence) ---
MAIL_MAILER=log               # à remplacer par un vrai transport
MAIL_FROM_ADDRESS="no-reply@xpr.ma"
MAIL_FROM_NAME="XPR Facture"
```

Reporter les valeurs de dev (`MAIL_HOST=mailpit`, `DB_HOST=postgres`,
`REDIS_HOST=redis`) revient à pointer des services qui n'existent pas dans la
fonction : chaque appel finit en timeout de connexion.

## 2. Variables du projet frontend

```dotenv
BACKEND_ORIGIN=https://<backend>.vercel.app   # variable SERVEUR, pas NEXT_PUBLIC_
NEXT_PUBLIC_BACKEND_URL=                      # vide : on passe par le proxy Next
```

---

## 2 bis. Voir les erreurs

`LOG_CHANNEL=stack` écrit dans `storage/logs/`, redirigé vers `/tmp` : les logs
meurent avec l'instance et n'apparaissent nulle part. `LOG_CHANNEL=stderr` les
envoie sur la sortie d'erreur, que Vercel collecte (onglet *Logs* du
déploiement, ou `vercel logs <url>`).

Complément immédiat : `APP_DEBUG=true` fait sortir le message d'exception dans
le champ `detail` de la réponse RFC 9457 (`ProblemDetailsRenderer`). Le corps de
la réponse 500 devient exploitable directement depuis l'onglet Réseau du
navigateur. À remettre à `false` ensuite — c'est une fuite d'implémentation.

---

## 3. Ce que le serverless invalide

### 3.1 La session Sanctum dépend du domaine déclaré — 500 à l'inscription

`statefulApi()` n'ajoute la pile de session que si l'origine de l'appel figure
dans `SANCTUM_STATEFUL_DOMAINS`. Sinon, `RegisterController` appelle
`$request->session()->regenerate()` sur une requête sans session et Laravel lève
`Session store not set on request.` — **500**, sur `register` comme sur `login`,
alors que `/up` répond 200.

Le message est déjà traduit en diagnostic explicite par `ProblemDetailsRenderer`
(titre « Session authentication is not available for this origin »), et le
comportement est verrouillé par `tests/Feature/Authentication/StatelessOriginTest.php`.
Le statut reste 500 délibérément : c'est le déploiement qui est mal configuré,
pas la requête.

L'origine lue est le `Referer` (à défaut l'`Origin`) transmis par le proxy Next,
donc le domaine du **frontend** — pas celui du backend.

### 3.2 Le disque est en lecture seule, `/tmp` est éphémère

`api/index.php` redirige `storage/` et `bootstrap/cache/` vers `/tmp`, ce qui
rend l'écriture possible. Cela ne la rend pas **durable** : `/tmp` est propre à
chaque instance. Toute donnée qui doit survivre à la requête sort de là —
sessions et cache en base, fichiers sur S3. Un `SESSION_DRIVER=file` ne
provoque pas d'erreur : il produit un utilisateur déconnecté à la requête
suivante, ce qui est plus long à diagnostiquer.

### 3.3 Aucun worker ne tourne

Il n'y a pas de `queue:work` : avec `QUEUE_CONNECTION=database`, les jobs
s'empilent et ne sont jamais consommés. `sync` les exécute dans la requête —
seul choix cohérent ici, au prix de la latence.

Corollaire : mettre un envoi d'e-mail « en file » ne le sort pas du chemin
critique. C'est pourquoi `User::sendEmailVerificationNotification()` isole
l'échec du transport au lieu de le déléguer. Un mail injoignable renvoyait un
500 sur un compte pourtant créé et commité : l'utilisateur ne pouvait ni se
connecter, ni recommencer avec la même adresse.

### 3.4 La latence réseau n'est plus négligeable

En conteneur, PostgreSQL est sur le même réseau. Ici chaque requête SQL traverse
Internet. Le jeu de démonstration créé à l'inscription émet de vrais documents
(numérotation, verrou de ligne compris) : de l'ordre de la centaine
d'allers-retours dans la requête d'inscription, sous un `maxDuration` de 30 s.
D'où `XPR_DEMO_DATA_ON_SIGNUP` (cf. `config/xpr.php`).

### 3.5 RLS et pooler : incompatibilité à traiter

`TenantContext` propage `app.company_id` via `set_config(..., false)`, en portée
**session**. Deux conséquences ici :

1. En local, le rôle `xpr_owner` est SUPERUSER : les policies ne s'appliquent
   jamais à lui, `FORCE ROW LEVEL SECURITY` compris. Sur Neon, `neondb_owner`
   n'est pas superuser et **possède** les tables : la RLS s'applique pour de
   bon, pour la première fois. Le déploiement est donc le premier
   environnement où cette seconde ligne de défense est réellement exercée —
   alors que la suite de tests ne la couvre pas (cf. CLAUDE.md §15, reliquat
   P0-09).
2. Sur l'endpoint **pooled** de Neon (hôte en `-pooler`, PgBouncer en mode
   transaction), une GUC de session posée hors transaction est perdue dès la
   requête suivante, qui peut atterrir sur une autre connexion serveur. Les
   policies ne trouvent alors aucun `app.company_id` et **filtrent tout** : les
   écrans se vident sans lever d'erreur.

L'inscription y échappe parce qu'elle tient dans une seule transaction. Les
lectures qui suivent, non. Tant que la propagation n'est pas passée en `SET
LOCAL` dans une transaction par requête, utiliser l'endpoint **direct** de Neon.

---

## 4. Ordre de mise en service

1. Migrations et seeders de référentiel (`CurrencySeeder`, `RoleSeeder`,
   `TaxRateSeeder`) : prérequis FK du schéma, joués depuis un poste local contre
   Neon — la fonction n'expose pas artisan.
2. Variables des deux projets (§1 et §2).
3. Redéployer : Vercel ne rejoue pas le build sur un simple changement de
   variable, et une variable ajoutée sans redéploiement reste sans effet.
