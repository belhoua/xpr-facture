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

#### Ce qui a été mesuré (2026-08-02)

Provisioning complet rejoué depuis un poste local contre la base de production,
en transaction annulée, avec le rôle `neondb_owner` et `FORCE ROW LEVEL
SECURITY` active :

| Configuration | Résultat |
|---|---|
| Endpoint direct, `legal_form = sarl` | 16 requêtes, passe |
| Endpoint direct, `legal_form = auto_entrepreneur` | passe |
| Endpoint **pooled**, les deux formes | passe |
| `companies` sous RLS ? | **non** — `relrowsecurity = false` |

Le chemin d'inscription est donc hors de cause sur les deux endpoints, et
`companies` ne porte aucune policy : un « bypass RLS » sur cette table n'a
pas d'objet.

#### `PDO::ATTR_EMULATE_PREPARES` : fausse piste, à ne pas retenter

L'émulation des requêtes préparées est la parade habituelle à PgBouncer en mode
transaction, où les requêtes préparées **nommées** de PDO se perdent d'une
connexion serveur à l'autre. Elle est inapplicable ici : elle interpole les
valeurs côté PHP, et un booléen PHP part alors en `0`/`1`, que PostgreSQL
refuse sur une colonne `boolean`.

```
column "vat_exempt" is of type boolean but expression is of type integer
```

Mesuré sur `companies`. Toute colonne booléenne casserait de la même façon.
La sortie reste l'endpoint direct, pas l'émulation.

### 3.6 Les caches applicatifs de Laravel ne s'appliquent pas ici

`config:cache`, `route:cache` et `event:cache` sont **actifs en conteneur** :
`docker/entrypoint.sh` les joue au démarrage, une fois les variables
d'environnement injectées par Render. Ils ne le sont **pas** sur Vercel, et ce
n'est pas un oubli.

Deux obstacles, dans cet ordre :

1. **Il n'y a pas d'étape où les jouer.** `vercel-php` construit la fonction en
   exécutant `composer install` ; il n'exécute aucune commande applicative, et
   la fonction déployée n'a pas d'accès artisan (cf. §4.1, qui joue déjà les
   migrations depuis un poste local pour la même raison).
2. **Le cache ne serait pas lu.** `api/index.php` déplace `bootstrap/cache/`
   vers `/tmp` — c'est ce qui rend l'écriture possible sur un paquet en lecture
   seule. Laravel y cherche donc `config.php` et `routes-v7.php`, alors qu'un
   fichier produit au build resterait dans le paquet. Seuls `packages.php` et
   `services.php` traversent, parce que le point d'entrée les **recopie**
   explicitement.

Un `config:cache` joué au build serait par ailleurs **dangereux**, et pas
seulement inefficace : il fige les valeurs lues au moment du build. Une
variable modifiée ensuite dans le tableau de bord Vercel n'aurait plus aucun
effet, y compris `APP_KEY` ou les identifiants de base — une panne silencieuse,
qui se manifesterait comme une authentification cassée sans message.

**Ce qui tient lieu d'optimisation ici**, c'est OPcache, fourni par le runtime :
le bytecode est conservé entre les requêtes servies par une même instance, ce
qui couvre l'essentiel de ce que le cache de configuration ferait gagner. Le
poste de coût réel du démarrage à froid n'est pas le parsing de la
configuration mais la **latence réseau vers Neon** (§3.4) — le levier est de
réduire le NOMBRE d'allers-retours SQL, pas le temps de démarrage de Laravel.

C'est ce que fait `DocumentItem::insertMany()` : les lignes d'un document
partent en un seul INSERT au lieu d'un par ligne. Sur ce déploiement, où chaque
requête traverse Internet, une facture de trente postes économise vingt-neuf
allers-retours — à l'intérieur de la transaction qui tient le verrou de
numérotation. Le budget est verrouillé par
`tests/Feature/Documents/DocumentQueryBudgetTest.php`.

---

## 4. Ordre de mise en service

1. Migrations et seeders de référentiel (`CurrencySeeder`, `RoleSeeder`,
   `TaxRateSeeder`) : prérequis FK du schéma, joués depuis un poste local contre
   Neon — la fonction n'expose pas artisan.
2. Variables des deux projets (§1 et §2).
3. Redéployer : Vercel ne rejoue pas le build sur un simple changement de
   variable, et une variable ajoutée sans redéploiement reste sans effet.
