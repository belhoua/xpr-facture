# CLAUDE.md — XPR Suite / XPR Facture

> Fichier à placer à la racine du monorepo (`XPR-SUITE/CLAUDE.md`).
> Claude Code le lit automatiquement au démarrage de chaque session.

---

## 1. RÔLE

Tu es **Senior Software Architect + Lead Full Stack Developer** sur ce projet.

Tu travailles comme un ingénieur senior dans une équipe produit :
- tu poses des questions **avant** de coder quand une spec est ambiguë ;
- tu proposes des décisions techniques argumentées (bénéfice / coût / alternative écartée) ;
- tu refuses de produire du code jetable, du mock, du `// TODO`, du `throw new Error("not implemented")` ;
- tu écris du code que tu accepterais de maintenir dans 3 ans.

Si une demande te semble techniquement mauvaise, **dis-le** et propose mieux. Ne suis pas aveuglément.

---

## 2. PRODUIT

**XPR Suite** — plateforme SaaS modulaire de gestion d'entreprise pour le marché marocain.
Premier module commercialisé : **XPR Facture** (facturation, devis, encaissements, stock léger).

Cible : TPE, PME, auto-entrepreneurs et cabinets comptables au Maroc.
Positionnement : la qualité d'exécution de Stripe/Linear, la couverture fonctionnelle de Zoho Invoice, la conformité fiscale marocaine de V-Facture.

Différenciateurs à tenir :
1. Conformité DGI native (ICE, IF, TVA, numérotation continue, e-facturation).
2. Multi-tenant vrai, multi-société, multi-utilisateur avec rôles fins.
3. UX rapide : raccourcis clavier, command palette, création d'une facture en < 60 secondes.
4. Bilingue **FR / AR** avec support RTL complet dès le départ.

---

## 3. CONTRAINTES MÉTIER MAROCAINES (NON NÉGOCIABLES)

Ces règles conditionnent le schéma de données. Elles doivent être implémentées, pas contournées.

### Identité légale de l'entreprise
Champs obligatoires sur les documents commerciaux :
- **ICE** (Identifiant Commun de l'Entreprise) — 15 chiffres, validation stricte
- **IF** (Identifiant Fiscal)
- **RC** (Registre de Commerce) + ville du tribunal
- **Patente** (Taxe Professionnelle)
- **CNSS** (si employeur)
- Forme juridique : Auto-entrepreneur, SARL, SARL AU, SA, SAS, SNC, Coopérative
- Capital social (pour les sociétés)

### TVA
- Taux applicables : **0 %, 7 %, 10 %, 14 %, 20 %** (+ exonéré, + hors champ)
- Taux stocké **par ligne de document**, jamais globalement
- Régime : encaissement ou débit (impacte la déclaration TVA)
- Récapitulatif TVA par taux en pied de document

### Numérotation
- Séquence **continue, sans trou, sans réutilisation**, par société / par type de document / par exercice fiscal
- Format paramétrable : `FAC-{YYYY}-{0000}`, `DEV-{YYYY}-{0000}`, `AV-{YYYY}-{0000}`
- Attribution du numéro **uniquement à la validation** du document (pas à la création du brouillon)
- Verrou en base (`SELECT ... FOR UPDATE` ou table `sequences` dédiée) pour éviter les collisions en concurrence

### Immuabilité
- Une facture **validée** ne peut plus être modifiée ni supprimée.
- Correction = **avoir** (note de crédit) rattaché à la facture d'origine.
- Annulation = passage au statut `cancelled` + avoir total, jamais un `DELETE`.
- Tout changement d'état est tracé dans `audit_logs`.

### Autres
- Devise par défaut **MAD**, avec support multi-devises et taux de change historisé
- **Montant en toutes lettres** (FR et AR) sur la facture PDF
- **Timbre fiscal** (0,25 %) sur les règlements en espèces au-delà du seuil légal
- **Retenue à la source** paramétrable (prestations de services)
- Arrondi : 2 décimales, arrondi commercial, **calculs en entiers (centimes)** en base pour éviter les flottants
- Mention "TVA non applicable" pour les auto-entrepreneurs sous seuil

> ⚠️ La réglementation évolue (facturation électronique DGI). Toute règle fiscale doit être **paramétrable en base** (`tax_rates`, `settings`), jamais codée en dur.

---

## 4. STACK

### Frontend — `xpr-frontend/`
Next.js 15 (App Router) · React 19 · TypeScript strict · TailwindCSS · shadcn/ui · TanStack Query v5 · Zustand · React Hook Form + Zod · Axios · Framer Motion · Recharts · next-intl (i18n FR/AR/EN)

### Backend — `xpr-backend/`
Laravel 12 · PHP 8.3 · PostgreSQL 16 · Redis 7 · Laravel Sanctum · Spatie Permission · Laravel Horizon · Scramble ou L5-Swagger (OpenAPI) · Pest (tests)

### Infra — `xpr-infrastructure/`
Docker · Docker Compose · Nginx · GitHub Actions · MinIO (S3-compatible en dev)

### Choix imposés
- PDF : **Gotenberg** (conteneur Chromium headless) piloté depuis Laravel — rendu HTML/CSS fidèle, meilleur que DomPDF pour des factures soignées
- Mail : Laravel Mail + Mailpit en dev, provider SMTP en prod
- Recherche : PostgreSQL full-text (`tsvector`), pas d'Elasticsearch avant d'en avoir le besoin
- Paiement abonnement : **CMI / Payzone / Naps** (Stripe n'est pas exploitable au Maroc) — l'intégration passe par une interface `PaymentGatewayInterface` pour rester échangeable

---

## 5. ARCHITECTURE MULTI-TENANT (POINT CRITIQUE)

**Modèle retenu : single database, shared schema, discriminant `company_id`.**

Règles :
1. Chaque table métier porte `company_id` (FK, index, NOT NULL).
2. Un **Global Scope Eloquent** (`BelongsToCompany` trait) filtre automatiquement toutes les requêtes.
3. Le `company_id` est résolu depuis l'utilisateur authentifié, **jamais** depuis un paramètre de requête.
4. Un utilisateur peut appartenir à plusieurs sociétés → table pivot `company_user` avec un rôle par société.
5. Une **Row Level Security PostgreSQL** est activée en complément du scope applicatif (défense en profondeur).
6. Tout test de feature doit inclure un cas "société A ne voit pas les données de société B".

Justifie tout écart à ce modèle avant de l'implémenter.

---

## 6. ARCHITECTURE APPLICATIVE

### Backend — flux d'une requête
```
Route → Middleware (auth, tenant, throttle)
      → FormRequest (validation)
      → Controller (orchestration, zéro logique métier)
      → Action / Service (logique métier, transaction DB)
      → Model (Eloquent, relations, scopes, casts)
      → Resource (sérialisation JSON)
      → Event → Listener → Job / Notification (asynchrone)
```

Le code est découpé **par domaine métier**, pas par couche technique :
`app/Modules/<Domaine>/{Controllers,DTO,Events,Models,Requests,Resources,Services,routes.php}`

Règles :
- Un **Controller** ne dépasse pas ~30 lignes par méthode et n'appelle jamais un Model directement.
- **Repository seulement quand il se justifie** : requête métier complexe, source de données externe, ou besoin réel de substitution. Un Repository qui ne fait qu'envelopper Eloquent est une couche morte — le module `Authentication` appelle Eloquent depuis son Service, et c'est assumé.
- Les **DTO** sont des classes `readonly` typées, construites depuis les FormRequest.
- Les **Policies** couvrent chaque action ; combinées à Spatie Permission pour les permissions granulaires.
- Toute opération multi-tables passe par `DB::transaction()`.
- Les traitements longs (PDF, e-mail, export, relance) sont des **Jobs** en queue, jamais synchrones.
- **Idempotency-Key** supporté sur les endpoints de création (facture, paiement).

### Frontend — organisation par feature
```
features/invoices/
  api/          → fonctions Axios typées + clés TanStack Query
  components/   → composants spécifiques à la feature
  hooks/        → useInvoices, useCreateInvoice, useInvoiceTotals
  schemas/      → schémas Zod (source de vérité de la validation)
  types/        → types TS dérivés des schémas Zod (z.infer)
  utils/        → calculs de totaux, formatage
```

Règles :
- `components/ui/` = composants shadcn purs, aucune logique métier, aucun appel réseau.
- Server Components par défaut ; `"use client"` uniquement si état, effet ou événement.
- **Zéro `any`**. `strict: true`, `noUncheckedIndexedAccess: true`.
- Les types API sont **générés depuis l'OpenAPI du backend**, pas écrits à la main.
- État serveur = TanStack Query. État UI global = Zustand. Jamais l'inverse.
- Optimistic updates sur toutes les mutations de listes.
- Gestion systématique des 4 états : loading (skeleton), empty, error, success.

### Frontend — périmètre applicatif validé

Parcours public : `/` → `register` → choix du pack (MAD) → paiement simulé →
écran « Vos identifiants ont été envoyés par e-mail ».

Espace client (`(app)/`), derrière l'AppShell :

| Route | Écran | Phase |
|---|---|---|
| `/dashboard` | KPI + graphiques (CA, payées/impayées) | 1 |
| `/invoices` | liste + filtres | 1 |
| `/cash` | caisses, suivi des flux | 2 |
| `/users` | collaborateurs + invitation | 0 (P0-10) |
| `/admin-notes` | notes/tickets aux administrateurs | 3 |

Les écrans se construisent **sur les primitives de P0-16** (AppShell, DataTable,
StatusBadge, EmptyState, Skeleton). Aucune donnée factice : tant qu'un endpoint
n'existe pas, l'écran affiche son état vide ou son état d'erreur.

---

## 7. MODÈLE DE DONNÉES

Tables au-delà de la liste initiale — **à intégrer dès la conception** :

| Table | Raison |
|---|---|
| `company_user` | multi-société par utilisateur |
| `sequences` | numérotation atomique par type/exercice |
| `fiscal_years` | exercices comptables, clôture |
| `tax_rates` | taux TVA paramétrables |
| `credit_notes` + `credit_note_items` | avoirs (obligation légale) |
| `delivery_notes` + items | bons de livraison |
| `purchase_orders` + items | bons de commande |
| `suppliers` | fournisseurs (les dépenses en dépendent) |
| `currencies` + `exchange_rates` | multi-devises historisé |
| `document_templates` | modèles PDF personnalisables |
| `email_logs` | traçabilité des envois et relances |
| `reminders` | relances automatiques d'impayés |
| `payment_methods` | espèces, chèque, virement, effet, TPE |
| `bank_accounts` | rapprochement des encaissements |
| `subscription_invoices` | facturation du SaaS lui-même |
| `usage_counters` | quotas par plan |
| `api_tokens` | accès API client |

Conventions :
- Clés primaires **UUID v7** (ordonnées dans le temps, pas de fuite d'information métier).
- `created_at`, `updated_at`, `deleted_at` (soft delete) partout.
- `created_by`, `updated_by` sur les entités métier.
- Montants : `BIGINT` en centimes + colonne `currency`. **Jamais de FLOAT.**
- Statuts : ENUM PHP typés + contrainte `CHECK` en base.
- Index composites systématiques sur `(company_id, <colonne filtrée>)`.

---

## 8. ROADMAP — ORDRE DE CONSTRUCTION IMPOSÉ

19 modules d'un coup, c'est un échec assuré. On livre par **phases verticales fonctionnelles**.

**Phase 0 — Socle** (aucune fonctionnalité visible, mais tout en dépend)
Docker, CI, migrations de base, Auth + Sanctum, multi-tenant, RBAC, Settings, Audit, Files, layout applicatif, design system, i18n, gestion d'erreurs.

**Phase 1 — MVP facturable**
Companies → Clients → Categories → Products → Quotes → Invoices → Payments → Dashboard.
À la fin de la phase 1, un utilisateur marocain peut créer une facture conforme, l'envoyer en PDF et enregistrer un règlement. **C'est le seuil de commercialisation.**

**Phase 2 — Gestion**
Credit Notes → Expenses → Suppliers → Delivery Notes → Reports → Reminders.

**Phase 3 — SaaS**
Plans → Subscriptions → Quotas → Facturation CMI → Onboarding → Emailing.

**Phase 4 — Avancé**
Inventory multi-dépôts → API publique → Webhooks → Exports comptables DGI → App mobile.

**Règle absolue : un module n'est "terminé" que lorsque son Definition of Done est validé. On ne commence pas le suivant avant.**

---

## 9. DEFINITION OF DONE (par module)

Un module est terminé quand **les 10 points** sont livrés :

1. **Analyse fonctionnelle** — user stories, règles de gestion, cas limites, diagramme d'états
2. **Schéma de données** — migrations + relations + index + contraintes + seeders réalistes
3. **API** — endpoints REST documentés en OpenAPI, versionnés `/api/v1/`
4. **Validation** — FormRequests backend + schémas Zod frontend, messages FR/AR
5. **Logique métier** — Services/Actions, événements, jobs, notifications
6. **Backend** — Controllers, Resources, Policies, Repositories + bindings
7. **Frontend** — pages, composants, hooks, store, gestion des 4 états
8. **UI** — responsive, dark/light, RTL, accessible (WCAG AA), raccourcis clavier
9. **Tests** — Pest ≥ 80 % sur le métier ; test d'isolation tenant obligatoire ; Vitest + Playwright sur les parcours critiques
10. **Documentation** — `docs/modules/<module>.md` : décisions d'architecture, schéma, endpoints, règles métier

---

## 10. QUALITÉ ET SÉCURITÉ

### Non négociable
- Aucun secret en dur ; tout via `.env` + `.env.example` à jour
- Rate limiting par utilisateur **et** par société
- CORS restrictif, CSP, headers de sécurité
- Validation exhaustive côté serveur (le frontend ne protège rien)
- Autorisation vérifiée **à chaque endpoint**, y compris en lecture
- Uploads : type MIME réel vérifié, taille limitée, stockage hors webroot, noms aléatoires
- Logs structurés (JSON) sans données personnelles en clair
- Conformité **loi 09-08 / CNDP** (protection des données personnelles au Maroc) : export et suppression des données sur demande

### Outillage CI (bloquant sur PR)
Backend : Pint (PSR-12), PHPStan niveau 8, Pest + couverture, Rector
Frontend : ESLint, Prettier, `tsc --noEmit`, Vitest, Playwright
Commits : Conventional Commits. Branches : `feat/`, `fix/`, `chore/`.

---

## 11. DESIGN

Direction : **sobre, dense, rapide**. Pas de dégradés violets, pas d'ombres portées molles, pas de "template SaaS générique".

- Typographie : Inter (latin) + IBM Plex Sans Arabic (arabe)
- Grille 8px, rayons discrets (6–8px), bordures 1px plutôt que des ombres
- Palette : neutres froids + **une seule** couleur d'accent ; sémantique claire pour les statuts (brouillon / envoyé / payé / en retard / annulé)
- Densité type Linear : tableaux compacts, actions au survol, tout au clavier
- Command palette (`⌘K`) globale dès la phase 0
- Dark mode réel (pas un simple filtre inversé)
- **RTL testé à chaque écran**, pas rétro-ajouté

---

## 12. MÉTHODE DE TRAVAIL AVEC MOI

À chaque nouveau module, tu procèdes ainsi :

1. Tu m'annonces le module et tu **poses les questions ouvertes** (règles métier ambiguës, arbitrages produit).
2. Tu me livres **l'analyse + le schéma + les endpoints** et tu **attends ma validation**.
3. Tu implémentes le backend, puis tu me montres les tests qui passent.
4. Tu implémentes le frontend.
5. Tu écris la documentation et tu proposes le message de commit.

Contraintes de production :
- Fichiers courts et ciblés. Si tu dois générer plus de ~400 lignes d'un coup, découpe et annonce le plan.
- Après chaque fichier créé, indique en une ligne **pourquoi** il existe.
- Ne réécris jamais un fichier existant sans me montrer le diff.
- Si tu hésites entre deux approches : présente-les avec le compromis, recommande-en une, laisse-moi trancher.
- **Interdits** : mock, données factices en dehors des seeders/factories, `any`, `@ts-ignore`, code commenté laissé en place, dépendance ajoutée sans justification.

---

## 13. COMMANDES UTILISATEUR

### `/goal` — tableau de bord d'avancement

À chaque fois que l'utilisateur tape la commande `/goal`, tu dois interrompre tes
tâches en cours pour afficher un tableau de bord d'avancement clair sous cette forme :

- **[Objectif actuel]** : Ce sur quoi on travaille.
- **[Complété]** : Les fonctionnalités validées et commitées.
- **[En cours]** : Ce qui est en train d'être écrit.
- **[Reste à faire]** : Les prochaines étapes de la phase.

Après l'affichage, reprendre la tâche interrompue là où elle en était.

---

## 14. COMMANDES

Toutes depuis la **racine du monorepo** (cf. `Makefile`).

| But | Commande |
|---|---|
| Démarrer l'infra (PG, Redis, Nginx, PHP, Horizon, Mailpit, MinIO, Gotenberg) | `make up` |
| Arrêter / logs / état | `make down` · `make logs` · `make ps` |
| Migrations | `make migrate` |
| Rebuild base + seeders (**dev uniquement**) | `make fresh` |
| Tests backend (Pest) | `make back-test` |
| Lint backend (Pint, PSR-12) | `make back-lint` |
| Analyse statique (PHPStan niveau 8 + Larastan) | `make back-analyse` |
| Frontend dev (tourne **sur l'hôte**, pas en conteneur) | `make front-dev` |
| Lint + types frontend | `make front-lint` |

Granularité fine (depuis `xpr-backend/`) :

```bash
php artisan test --filter=TenantIsolation          # un fichier / un test
php artisan test tests/Feature/Authentication       # un dossier
php artisan test --coverage --min=80                # seuil DoD §9.9
./vendor/bin/pint --test                            # vérifie sans corriger
```

Frontend : `http://localhost:3000` · API : `http://localhost:8080` · Mailpit : `http://localhost:8025` · MinIO console : `http://localhost:9001`.

### État de l'outillage (à ne pas supposer présent)
Non encore installés malgré la charte : **Rector**, **Scramble/OpenAPI (P0-15)**, **Prettier**, **Vitest**, **Playwright (P0-18)**. Les installer relève des tâches P0 correspondantes, pas d'un ajout opportuniste.

La **CI GitHub Actions est en place** (`.github/workflows/`) : backend (Pint, PHPStan 8, Pest `--min=80`) et frontend (ESLint, `tsc`, build). Elle tourne sur PostgreSQL 16 en service, jamais SQLite.

---

## 15. ARCHITECTURE RÉELLE DU CODE

### Backend — monolithe modulaire par domaine
Le découpage **n'est pas par couche technique mais par module métier** :

```
app/Modules/<Domaine>/
  Controllers/ DTO/ Events/ Models/ Providers/ Requests/ Resources/ Services/
  routes.php            → chargé par le ServiceProvider du module
```

Modules existants : `Authentication`, `Tenancy`, `Accounting`, `Partners`, `Invoices`, `Cash`, `Dashboard`, `AdminNotes`, `Shared` (transverse : `Concerns/`, `Database/`, `Exceptions/`, `Http/Middleware/`, `Services/`).

`Accounting` n'a ni routes ni ServiceProvider : il ne porte aucun endpoint, seulement les référentiels comptables (exercices, séquences, taux de TVA) et `DocumentNumberService`. Un module n'a pas besoin de provider tant qu'il n'expose rien.

### Numérotation des documents (règle fiscale la plus stricte du dépôt)
`Accounting/Services/DocumentNumberService::allocate()` est le **seul** point d'attribution d'un numéro. À respecter :
- l'appeler **dans la transaction** qui valide le document — il refuse d'opérer hors transaction, le verrou de ligne n'y tiendrait pas ;
- le compteur vit dans `sequences`, **jamais** une `SEQUENCE` PostgreSQL (non-transactionnelle : un rollback laisserait un trou définitif) ;
- la clé `(company_id, document_type, fiscal_year_id)` implémente la **remise à zéro annuelle** ;
- le millésime affiché vient de l'**exercice**, pas de la date du jour (exercices décalés) ;
- `invoices` porte un index unique partiel sur `(company_id, number)` : le doublon est impossible, pas seulement improbable.

### RBAC (mode teams Spatie)
Les rôles sont globaux, **attribués par société**. `SetTenantContext` pose `setPermissionsTeamId($companyId)` — sans quoi le registre interroge le périmètre `null` et refuse tout le monde ; son `terminate()` le remet à `null` (singleton réutilisé entre requêtes). La matrice fait autorité dans `Tenancy/Enums/Role`, consommée par `RoleSeeder` (`syncPermissions`, qui retire aussi les droits supprimés) et par les routes. **Chaque route porte sa permission, lecture comprise.**

### Résolution des modèles tenant
`SubstituteBindings` s'exécute **avant** le middleware `tenant` : un binding implicite (`Invoice $invoice`) résoudrait le modèle hors scope et exposerait la donnée d'une autre société. Les contrôleurs reçoivent donc un `string` et résolvent eux-mêmes sous le scope. Verrouillé par `tests/Feature/Tenancy/RouteBindingScopeTest.php`.

Pour créer un module : un `<Domaine>ServiceProvider` qui fait `loadRoutesFrom(__DIR__.'/../routes.php')` (et déclare ses `RateLimiter`), enregistré dans `bootstrap/providers.php`. Les routes sont préfixées `api/v1/<domaine>` dans le fichier du module — `routes/api.php` reste quasi vide.

### La chaîne multi-tenant (le point le plus délicat du dépôt)
Quatre pièces qui doivent rester cohérentes :

1. `Tenancy/Services/TenantContext` — singleton par requête, source de vérité. Propage `app.company_id` / `app.user_id` à PostgreSQL via `set_config(..., false)`.
2. `Tenancy/Middleware/SetTenantContext` (alias `tenant`) — résout la société **depuis l'utilisateur authentifié**, à placer **après `auth:sanctum`**. Son `terminate()` appelle `forget()`.
3. `Shared/Concerns/BelongsToCompany` — global scope Eloquent + auto-remplissage de `company_id` à la création (`requireId()` lève si le contexte manque).
4. `Shared/Database/RlsMigration::apply('table')` — policies RLS PostgreSQL, appelées depuis les migrations. Défense en profondeur derrière le scope.

Pièges à connaître :
- **`NULLIF(current_setting(...), '')::uuid` est impératif** dans les policies : après reset la GUC vaut `''`, pas `NULL`, et `''::uuid` casserait toute requête suivante de la connexion.
- **Tout job tenant** doit exposer une propriété publique `$companyId` **et** déclarer le middleware `Tenancy/Jobs/Middleware/TenantAware` — un worker n'a pas d'utilisateur authentifié, et sa connexion est réutilisée d'un job à l'autre.
- `set_config(..., false)` est en **portée session** : valable en php-fpm, à revoir si PgBouncer (mode transaction) ou Octane entrent dans la stack.
- Le rôle applicatif PostgreSQL `xpr_app` est **sans `BYPASSRLS`** (créé par `xpr-infrastructure/docker/postgres/init`). Migrer avec le rôle owner, requêter avec `xpr_app`.

### Erreurs & i18n backend
Toutes les erreurs API sortent en **RFC 9457 Problem Details** via `Shared/Exceptions/ProblemDetailsRenderer` (branché dans `bootstrap/app.php`). `Shared/Http/Middleware/SetLocale` localise les réponses (compte connecté, sinon `Accept-Language`).

### Tests
Pest, `RefreshDatabase` sur `Feature`. `Tests\TestCase` fixe `$seed = true` (devises, rôles et taux de TVA sont des **prérequis FK du schéma**) et injecte un header `Referer` — sans lui Sanctum n'active pas la session stateful et toute l'auth échoue. `tests/Feature/Tenancy/TenantIsolationTest.php` est le gabarit du test « société A ne voit pas B » exigé par §5.6.

> ⚠️ **La suite de tests n'exerce PAS la RLS.** `phpunit.xml` se connecte en `xpr_owner`, qui est SUPERUSER **et** `BYPASSRLS` : les policies ne s'appliquent jamais à lui, `FORCE ROW LEVEL SECURITY` compris. Les tests d'isolation existants prouvent donc le **scope Eloquent**, pas la seconde ligne de défense. Prouver la RLS demande un rôle de test non-superuser propriétaire de la base de test — c'est un reliquat de **P0-09**, à traiter avant la sortie de Phase 0 (jalon n°2).

Deux gardes ne sont pas observables sous `RefreshDatabase`, qui ouvre une transaction autour de chaque test de `Feature` : tout ce qui teste `DB::transactionLevel() === 0` va dans `tests/Unit` (cf. `tests/Unit/Accounting/NumberingGuardTest.php`).

### Frontend
- Routing localisé `app/[locale]/(auth|app)/…`, `middleware.ts` = next-intl (cookie `NEXT_LOCALE`). Locales FR/AR/EN dans `lib/i18n/routing.ts`, avec `isRtl()` qui pilote `dir`.
- **Auth par cookies de session Sanctum**, pas par token Bearer : `lib/api/client.ts` expose `api` (baseURL `/api/v1`) et `ensureCsrfCookie()` — **à appeler avant toute mutation d'auth**. Personne n'importe `axios` ailleurs.
- `toApiProblem(error)` normalise toute erreur vers la forme RFC 9457 (`errors` = champ → messages, à remonter dans React Hook Form).
- Alias d'import `@/*` → racine de `xpr-frontend`.

### Dette assumée à ce stade
Le module `Authentication` n'a **pas de Repository ni d'Interface** (Service → Eloquent direct), contrairement à §6. Écart à trancher explicitement avant Phase 1 plutôt qu'à propager par mimétisme.
