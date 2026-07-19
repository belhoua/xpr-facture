# Phase 0 — Plan de construction du socle

> Livrable 4. Points en suite de Fibonacci (1 = ~½ journée, 8 = ~1 semaine pour
> un dev senior). Les dépendances définissent l'ordre ; les tâches sans lien
> entre elles peuvent avancer en parallèle (back/front notamment).
> **Total : 66 points.**

| # | Tâche | Pts | Dépend de | Contenu / critère de sortie |
|---|-------|-----|-----------|------------------------------|
| P0-01 | Monorepo & conventions | 2 | — | Arborescence `xpr-backend` / `xpr-frontend` / `xpr-infrastructure`, `.editorconfig`, commitlint (Conventional Commits), templates PR, CODEOWNERS |
| P0-02 | Environnement Docker dev | 5 | P0-01 | Compose : PHP 8.3-fpm, PostgreSQL 16, Redis 7, Nginx, Mailpit, MinIO, Gotenberg. `make up` fonctionne sur machine vierge ; volumes persistants ; healthchecks |
| P0-03 | Squelette Laravel 12 | 3 | P0-02 | Config env, Pint, PHPStan niv. 8, Pest, Rector, Horizon branché Redis, `.env.example` complet, logs JSON + request-id |
| P0-04 | Squelette Next.js 15 | 3 | P0-02 | TS `strict` + `noUncheckedIndexedAccess`, ESLint/Prettier, Tailwind, shadcn/ui init, next-intl (FR/AR/EN), Axios configuré |
| P0-05 | CI GitHub Actions | 3 | P0-03, P0-04 | Pipelines back (pint, phpstan, pest+couverture) et front (eslint, tsc, vitest) **bloquants** sur PR ; cache dépendances |
| P0-06 | Migrations socle + seeders | 5 | P0-03 | Tout `01-schema-phase-0.sql` en migrations : fonction uuid v7, users, companies, company_user, currencies, exchange_rates, tax_rates, fiscal_years, sequences, settings, audit_logs, files, idempotency_keys. Seeders : devises, taux TVA marocains, société de démo |
| P0-07 | Auth Sanctum (SPA cookies) | 5 | P0-06 | Register (avec création de société), login, logout, reset password, vérification e-mail ; pages front correspondantes ; rate limiting sur /login |
| P0-08 | Contexte multi-tenant | 5 | P0-07 | Trait `BelongsToCompany` (global scope + auto-fill `company_id`), middleware de résolution (utilisateur → société active), switch de société (UI + endpoint), middleware de job Horizon propageant `company_id` |
| P0-09 | RLS PostgreSQL | 5 | P0-08 | Rôle `xpr_app` sans BYPASSRLS, `SET LOCAL` par transaction, policies sur toutes les tables tenant. **Tests d'isolation Pest : A ne voit pas B, en HTTP et en job** |
| P0-10 | RBAC Spatie (mode teams) | 5 | P0-08 | `team_foreign_key = company_id`, rôles seedés (owner, admin, accountant, sales, viewer), Policies de base, middleware de permissions, invitations avec rôle |
| P0-11 | Module Settings | 3 | P0-10 | Service de lecture (fallback global → société, cache Redis), endpoints CRUD scopés, écran de paramètres société (identité légale, préférences) |
| P0-12 | Audit logs | 3 | P0-08 | Écriture automatique (observer + événements explicites pour les transitions), endpoint de consultation filtré, écran timeline |
| P0-13 | Fichiers & uploads | 3 | P0-08 | Service upload MinIO/S3 : MIME réel vérifié, taille limitée, nom aléatoire, URLs signées temporaires ; suppression liée au propriétaire |
| P0-14 | Gestion d'erreurs API | 2 | P0-03, P0-04 | Format d'erreur unifié (RFC 9457 Problem Details), handler Laravel, messages de validation FR/AR, intercepteur Axios + mapping vers toasts/formulaires |
| P0-15 | OpenAPI → types TS | 3 | P0-07, P0-14 | Scramble branché, spec `/api/v1` publiée, génération `openapi-typescript` en script + vérif CI (spec et types front ne divergent jamais) |
| P0-16 | Design system & layout | 8 | P0-04 | Tokens (couleurs sémantiques statuts, grille 8px), dark/light réel, **RTL fonctionnel**, layout app (sidebar, topbar, breadcrumb), command palette ⌘K, composants de base (DataTable dense, EmptyState, Skeleton, StatusBadge) |
| P0-17 | i18n complet | 3 | P0-16 | Dictionnaires FR/AR/EN, bascule de langue + direction, formats dates/nombres/devises localisés (MAD), messages backend localisés |
| P0-18 | Harness de tests E2E | 3 | P0-07, P0-16 | Playwright configuré (multi-locale, RTL screenshot), axe-core intégré (WCAG), premier parcours : inscription → login → switch société ; Vitest branché sur les composants du DS |
| P0-19 | Observabilité & sauvegardes | 3 | P0-02, P0-03 | Sentry back+front corrélés par request-id, healthcheck endpoint, WAL archiving + script de sauvegarde/restauration **testé** (une restauration jouée au moins une fois) |
| P0-20 | Documentation socle | 2 | tout | `docs/modules/socle.md` : décisions (ADR courts), schéma, conventions tenant/RLS, checklist DoD outillée |

## Chemin critique

```
P0-01 → P0-02 → P0-03 → P0-06 → P0-07 → P0-08 → P0-09/P0-10 → P0-11
                P0-04 → P0-16 → P0-17/P0-18
```

Le backend (06→10) et le front (04→16→17) avancent en parallèle après P0-05 ;
ils se rejoignent sur P0-07 (auth) et P0-15 (types générés).

## Jalons de sortie de Phase 0

1. Un utilisateur s'inscrit, crée sa société (identité légale complète), invite
   un collègue avec un rôle, bascule entre deux sociétés.
2. Les tests d'isolation tenant passent en HTTP **et** en job.
3. La CI bloque : lint, types, tests, couverture, divergence OpenAPI.
4. L'interface fonctionne en AR/RTL et en dark mode sans régression visuelle.
5. Une sauvegarde a été restaurée avec succès au moins une fois.
