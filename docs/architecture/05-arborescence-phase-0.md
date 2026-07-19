# Proposition — Arborescence physique & premier lot de migrations (Phase 0)

> Statut : **en attente de validation**. Rien n'est exécuté tant que ce document
> n'est pas approuvé. Trois divergences assumées par rapport à la demande sont
> signalées § 4.

## 1. Backend — `xpr-backend/`

Laravel 12 standard, plus `app/Modules/` organisé par module fonctionnel.
Chaque module possède son ServiceProvider (enregistré dans `bootstrap/providers.php`)
et son fichier de routes, montés sous `/api/v1`.

```
xpr-backend/
├── app/
│   ├── Modules/
│   │   ├── Shared/                          # socle transverse (pas un module métier)
│   │   │   ├── Concerns/
│   │   │   │   ├── BelongsToCompany.php     # global scope + auto-fill company_id
│   │   │   │   └── HasUuidV7.php
│   │   │   ├── Database/
│   │   │   │   └── RlsMigration.php         # helper : applique enable/force/policy NULLIF
│   │   │   ├── DTO/
│   │   │   │   └── DataTransferObject.php   # base readonly, ::fromRequest()
│   │   │   ├── Exceptions/
│   │   │   │   └── ProblemDetailsHandler.php# RFC 9457, messages FR/AR
│   │   │   ├── Http/
│   │   │   │   ├── ApiController.php
│   │   │   │   └── Middleware/IdempotencyKey.php
│   │   │   └── ValueObjects/
│   │   │       ├── Money.php                # centimes + devise, arrondi commercial
│   │   │       └── Ice.php                  # validation 15 chiffres
│   │   ├── Authentication/
│   │   │   ├── Controllers/                 # Login, Register, Logout, PasswordReset
│   │   │   ├── DTO/
│   │   │   ├── Requests/
│   │   │   ├── Resources/
│   │   │   ├── Services/                    # RegistrationService (user + société + rôles)
│   │   │   ├── Events/
│   │   │   ├── Notifications/
│   │   │   ├── Models/User.php              # table globale, hors RLS
│   │   │   ├── Providers/AuthenticationServiceProvider.php
│   │   │   └── routes.php
│   │   ├── Tenancy/
│   │   │   ├── Controllers/                 # Company CRUD, CompanySwitch, invitations
│   │   │   ├── DTO/
│   │   │   ├── Requests/
│   │   │   ├── Resources/
│   │   │   ├── Services/
│   │   │   │   ├── TenantContext.php        # SET LOCAL app.company_id (transaction)
│   │   │   │   └── CompanyProvisioning.php  # seed TVA, moyens de paiement, séquences
│   │   │   ├── Middleware/
│   │   │   │   ├── SetTenantContext.php
│   │   │   │   └── EnsureCompanyMember.php
│   │   │   ├── Jobs/Middleware/TenantAware.php  # propage company_id dans les jobs
│   │   │   ├── Models/                      # Company.php, CompanyUser.php
│   │   │   ├── Providers/TenancyServiceProvider.php
│   │   │   └── routes.php
│   │   ├── Settings/        { Controllers, Requests, Resources, Services, Models, routes.php }
│   │   ├── Audit/           { Listeners, Resources, Services, Models, Controllers, routes.php }
│   │   └── Files/           { Controllers, Requests, Services, Models, routes.php }
│   ├── Http/ ...                            # kernel/middleware globaux Laravel standard
│   └── Providers/ ...
├── database/
│   ├── migrations/                          # cf. § 3
│   ├── seeders/                             # CurrencySeeder, TaxRateSeeder, RoleSeeder, DemoCompanySeeder
│   └── factories/
├── tests/
│   ├── Feature/ {Authentication, Tenancy}/  # dont l'isolation A/B obligatoire
│   └── Unit/Shared/                         # Money, Ice, DTO
├── config/ …, composer.json, phpstan.neon, pint.json, rector.php
```

**Pas de dossiers `Repositories/` ni `Interfaces/` dans Authentication** :
l'authentification n'a aucune requête complexe ni substitution plausible —
`SequenceRepository` (Phase 1) sera le premier vrai repository, dans son module.
Cf. divergence D2.

## 2. Frontend — `xpr-frontend/`

```
xpr-frontend/
├── app/
│   └── [locale]/                            # next-intl, dir=rtl si ar
│       ├── (auth)/
│       │   ├── login/page.tsx
│       │   ├── register/page.tsx
│       │   └── forgot-password/page.tsx
│       ├── (app)/                           # protégé, AppShell
│       │   ├── layout.tsx                   # sidebar + topbar + ⌘K
│       │   ├── dashboard/page.tsx
│       │   └── settings/…                   # société, profil, membres
│       └── layout.tsx                       # <html lang dir>, thème
├── components/
│   ├── ui/                                  # shadcn générés, zéro logique métier
│   └── layout/                              # AppShell, Sidebar, Topbar, CommandPalette,
│                                            # ThemeToggle, LocaleSwitcher, StatusBadge
├── features/
│   ├── auth/      { api/, components/, hooks/, schemas/, types/ }
│   └── tenancy/   { api/, components/ (CompanySwitcher), hooks/, schemas/, types/ }
├── lib/
│   ├── api/
│   │   ├── client.ts                        # instance Axios (cookies, CSRF, intercepteur erreurs)
│   │   └── generated/                       # types issus de l'OpenAPI backend (P0-15)
│   ├── i18n/                                # config next-intl, routing
│   └── utils.ts                             # cn(), formatMoney(MAD)…
├── stores/                                  # Zustand : ui.ts (thème, sidebar, palette)
├── messages/ { fr.json, ar.json, en.json }
├── middleware.ts                            # next-intl + garde d'auth
├── app/globals.css                          # Tailwind v4 : @theme (tokens), dark, RTL
├── components.json                          # shadcn (style, alias)
├── tsconfig.json                            # strict + noUncheckedIndexedAccess
├── next.config.ts, postcss.config.mjs, eslint.config.mjs, .prettierrc
```

Choix : **Tailwind v4** (config CSS via `@theme`, plus de `tailwind.config.js`) —
propriétés logiques natives (`ms-*`/`me-*`) qui rendent le RTL quasi gratuit,
et c'est la cible actuelle de shadcn/ui.

## 3. Premier lot de migrations (ordre exact)

Transposition fidèle de `01-schema-phase-0.sql` **déjà validé contre PostgreSQL**,
découpée pour respecter les FK :

| Ordre | Migration | Contenu |
|---|---|---|
| 0001 | `create_uuid_v7_function` | `uuid_generate_v7()` (DDL brut) |
| 0002 | `create_currencies_table` | référentiel global (FK requis par companies) |
| 0003 | `create_companies_table` | identité légale complète (ICE CHECK 15 chiffres, forme juridique, régime TVA…) |
| 0004 | `create_users_table` | globale, citext, locale, `default_company_id` FK |
| 0005 | `create_company_user_table` | pivot + **RLS** (enable + force + policy) |
| 0006 | `setup_spatie_permission` | mode teams, `team_foreign_key = company_id` |

Politique RLS appliquée par le helper `RlsMigration` (le correctif validé) :

```sql
ALTER TABLE company_user ENABLE ROW LEVEL SECURITY;
ALTER TABLE company_user FORCE ROW LEVEL SECURITY;
CREATE POLICY tenant_isolation ON company_user
  USING     (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid)
  WITH CHECK (company_id = NULLIF(current_setting('app.company_id', true), '')::uuid);
```

Le rôle `xpr_app` (sans `BYPASSRLS`) est créé par l'init Docker
(`xpr-infrastructure/docker/postgres/init/01-roles.sql`), pas par une migration :
les migrations tournent avec le rôle owner.

**Précision importante** : la RLS porte sur `company_user` et les futures tables
tenant — **pas** sur `companies` ni `users`, qui sont des tables globales
(un utilisateur appartient à N sociétés ; les filtrer par `company_id` n'a pas
de sens). Leur accès est contrôlé par les Policies + le pivot. C'est le modèle
validé au cadrage.

## 4. Divergences assumées avec la demande (à trancher)

| # | Demandé | Proposé | Raison |
|---|---|---|---|
| D1 | Squelettes `Modules/Clients`, `Modules/Invoices`… dès maintenant | Seulement les modules Phase 0 (Shared, Authentication, Tenancy, Settings, Audit, Files) | Des dossiers vides pendant des semaines mentent sur l'état du produit et gèlent des choix de structure avant l'analyse du module (méthode §12) ; créer un module = 30 s le moment venu |
| D2 | `Repositories/` + `Interfaces/` dans Authentication | Aucun repository en Phase 0 ; premier vrai cas : `SequenceRepository` en Phase 1 | Décision « repositories ciblés » du cadrage (critique §2.2) ; l'auth n'a que des lectures triviales |
| D3 | `services/` et `store/` à la racine frontend | `features/*/api` + `lib/api` ; `stores/` | Le CLAUDE.md impose l'organisation par feature : un `services/` global recrée la couche fourre-tout qu'on a interdite |

## 5. Périmètre du premier lot de code (après validation)

1. Scripts d'initialisation des trois dépôts (arborescences ci-dessus + configs).
2. Migrations 0001 → 0006 + seeders (devises, rôles).
3. `RlsMigration`, `TenantContext`, `BelongsToCompany` (avec leurs tests Pest,
   dont l'isolation A/B).
4. Test Pest vérifiant qu'une connexion sans contexte ne voit **aucune** ligne
   de `company_user` (reproduit le bug NULLIF en régression).
