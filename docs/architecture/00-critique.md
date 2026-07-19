# Critique du document de cadrage (CLAUDE.md)

> Livrable 1 de la première tâche. Objectif : identifier ce qui manque, ce qui est
> sur-dimensionné et les risques, **avant** d'écrire une ligne de code.

## Ce qui est solide (et qu'on garde tel quel)

- Montants en `BIGINT` centimes, statuts `CHECK`és, UUID v7, index composites `(company_id, …)`.
- Numérotation à la validation uniquement + verrou en base : c'est le bon modèle.
- Phases verticales avec seuil de commercialisation explicite en fin de Phase 1.
- Gotenberg plutôt que DomPDF : indispensable pour un rendu AR/RTL correct.
- Règles fiscales paramétrables en base plutôt que codées en dur.

---

## 1. Ce qui manque

### 1.1 Les avoirs sont hors MVP — c'est une incohérence légale (bloquant)

Le document impose l'immuabilité : « une facture validée ne peut plus être modifiée,
correction = avoir ». Mais les avoirs (`credit_notes`) sont en **Phase 2**. Résultat :
pendant toute la commercialisation de la Phase 1, un utilisateur qui valide une facture
avec une erreur n'a **aucune voie de correction**. C'est intenable en production réelle
(première erreur de saisie = ticket support insoluble).

**Recommandation : remonter les avoirs en Phase 1.** Le schéma les inclut déjà
(voir `02-schema-phase-1.sql`) ; le coût marginal est faible car un avoir est
structurellement une facture inversée.

### 1.2 L'immuabilité exige des snapshots, pas seulement un verrou

Interdire l'`UPDATE` ne suffit pas : si la facture référence `clients.address` et que le
client déménage, le PDF régénéré deux ans plus tard est **faux**. Idem si la société change
de RC ou de capital. Il faut, à la validation :

- un **snapshot JSONB** de l'identité légale du vendeur et de l'acheteur sur le document ;
- le **taux de TVA copié sur chaque ligne** (pas seulement la FK vers `tax_rates`) ;
- le **PDF archivé** comme fichier (le PDF émis fait foi, pas une re-génération).

Le schéma livré intègre les trois.

### 1.3 Attribution du numéro : le trou par rollback n'est pas traité

`SELECT ... FOR UPDATE` évite les collisions, pas les trous : si on incrémente la séquence
puis que la transaction de validation échoue plus loin (PDF, contrainte), le numéro est
consommé et la séquence a un trou. Règle à graver : **l'incrément de séquence et le passage
au statut `validated` vivent dans la même transaction DB**, et tout ce qui peut échouer
(génération PDF, e-mail) se fait **après** commit, en job. Le document ne le dit pas ; je
l'ajoute comme règle d'implémentation.

### 1.4 RLS : le principe est posé, pas le mécanisme opérationnel

Trois points à trancher dès la Phase 0, sinon la RLS sera désactivée « temporairement » un
jour de rush :

- **Propagation du contexte** : middleware qui exécute `SET LOCAL app.company_id = …` en
  début de transaction. `SET LOCAL` impose que les requêtes tenant tournent dans une
  transaction ; avec PgBouncer en *transaction pooling*, un `SET` de session fuit entre
  clients — donc `SET LOCAL` obligatoire.
- **Jobs Horizon** : un worker n'a pas d'utilisateur authentifié. Chaque job doit porter
  `company_id` dans son payload et restaurer le contexte (middleware de job dédié).
  Sans ça, la RLS bloque les jobs — ou pire, le scope Eloquent absent expose tout.
- **Rôles PostgreSQL** : l'application se connecte avec un rôle **non-owner sans
  `BYPASSRLS`** ; les migrations tournent avec le rôle owner. `FORCE ROW LEVEL SECURITY`
  sur toutes les tables tenant.

### 1.5 Rétention légale vs soft delete vs CNDP — conflit non arbitré

- Code de commerce marocain : conservation des documents comptables **10 ans**.
- Loi 09-08 / CNDP : droit à l'effacement des données personnelles.
- Le document impose `deleted_at` partout.

Ces trois exigences se contredisent si on ne les hiérarchise pas. Position proposée :
les **documents validés ne sont jamais supprimés ni soft-deletés** (seuls les brouillons
le sont) ; l'effacement CNDP s'applique aux données de compte et prospects, et se traduit
par une **anonymisation** des clients référencés par des documents validés (les mentions
légales du snapshot restent, car obligation légale prime). À valider avec toi, puis à
documenter.

### 1.6 Absents du socle alors qu'ils en font partie

- **`idempotency_keys`** : l'Idempotency-Key est exigée mais aucune table ne la porte.
  Ajoutée en Phase 0.
- **Sauvegardes / PITR** : pour un SaaS financier, RPO/RTO doivent être définis avant le
  premier client (WAL archiving + `pg_basebackup` ou service managé). Une ligne dans la
  Phase 0 infra.
- **Observabilité** : rien sur Sentry / métriques / uptime. Minimum Phase 0 : Sentry
  (back + front) et logs JSON corrélés par request-id.
- **Fuseau horaire** : tout en UTC en base, `Africa/Casablanca` pour l'affichage et pour
  déterminer la date d'émission — à figer, ça conditionne `issue_date` et les exercices.

### 1.7 Points techniques non anticipés

- **UUID v7 n'est pas natif en PostgreSQL 16** (natif en 18). Je fournis une fonction SQL
  `uuid_generate_v7()` pour les `DEFAULT`, et Laravel 12 (`HasVersion7Uuids`) côté app.
- **Montant en toutes lettres en arabe** : aucune librairie PHP fiable et maintenue ne
  couvre correctement les accords du tamyiz et les devises (dirham/centime). Prévoir une
  implémentation maison **fortement testée** (Pest, table de cas) — petite mais pas gratuite.
- **Recherche full-text arabe** : PostgreSQL n'a pas de configuration `arabic` native.
  Les `tsvector` utiliseront `simple` (+ `unaccent` pour le latin) ; c'est suffisant pour
  des noms de clients/produits, mais il faut le savoir avant de promettre mieux.
- **PDF arabe dans Gotenberg** : fontes embarquées (IBM Plex Sans Arabic) dans le HTML,
  et décision produit à prendre : chiffres occidentaux (0-9) ou hindous (٠-٩) sur les
  montants ? Recommandation : occidentaux partout (usage bancaire marocain), à confirmer.
- **e-facturation DGI** : le format cible n'est pas encore publié/stabilisé. La seule
  protection : une couche d'export par document (`DocumentExporterInterface`) et les
  snapshots complets — on ne peut rien implémenter de plus aujourd'hui, et il ne faut pas
  essayer.

---

## 2. Ce qui est sur-dimensionné

### 2.1 « 17 tables à intégrer dès la conception »

Concevoir ≠ migrer. Créer aujourd'hui les migrations de `purchase_orders`,
`subscription_invoices`, `usage_counters`, `api_tokens`… c'est du schéma mort qui sera
faux au moment où on en aura besoin (on aura appris entre-temps). Ce que je fais à la
place : le schéma Phase 0+1 **réserve les conventions** (nommage, colonnes communes,
pattern items) pour que ces tables s'y insèrent sans refactoring, mais seules les tables
des Phases 0 et 1 existent. `bank_accounts` et `payment_methods` sont remontées en
Phase 1 car les encaissements en dépendent directement.

### 2.2 Repository + Interface systématiques

Une interface par repository « pour la testabilité » alors qu'Eloquent est déjà
substituable (factories + SQLite/Postgres de test) produit surtout de la cérémonie :
deux fichiers par entité qui délèguent à Eloquent. Compromis proposé :

- **Actions/Services** portent la logique métier et les transactions (inchangé) ;
- repositories **uniquement** là où la substitution est plausible ou la requête complexe :
  `SequenceRepository` (verrou), reporting/dashboard, recherche full-text ;
- accès Eloquent direct (scopé tenant) pour le CRUD simple, dans les Actions.

Je recommande cette version ; si tu tiens au pattern intégral, je l'appliquerai, mais je
voulais le coût sur la table.

### 2.3 Multi-devises historisé complet en MVP

Le schéma le supporte (colonnes `currency` + `exchange_rate` sur chaque document,
table `exchange_rates`) — c'est le bon moment pour le poser. Mais l'**UX** multi-devises
(saisie de taux, conversion à l'écran, rapports consolidés) doit sortir du périmètre
Phase 1 : la cible TPE/PME marocaine facture à ~98 % en MAD. Schéma oui, interface plus tard.

### 2.4 Definition of Done : lourde mais tenable, à une condition

WCAG AA + RTL + raccourcis + 80 % + Playwright sur chaque module, c'est le bon standard —
à condition que la vérification soit **outillée et automatique** (axe-core dans Playwright,
job RTL par capture d'écran, couverture en CI), pas un audit manuel par module. Sinon la
DoD sera ignorée dès le module 3. La Phase 0 inclut cet outillage.

---

## 3. Risques principaux (résumé)

| # | Risque | Parade |
|---|--------|--------|
| R1 | Correction impossible sans avoirs en Phase 1 | Avoirs remontés en Phase 1 |
| R2 | Trous de numérotation par rollback | Numéro + validation dans la même transaction ; effets de bord après commit |
| R3 | RLS contournée par les jobs / le pooling | Contexte tenant dans le payload des jobs, `SET LOCAL`, rôle DB sans BYPASSRLS |
| R4 | PDF régénérés faux après modification du client | Snapshots JSONB + PDF archivé à la validation |
| R5 | Montant en lettres AR incorrect (image produit) | Implémentation dédiée + table de tests exhaustive |
| R6 | DoD abandonnée sous pression | Vérifications automatisées en CI dès la Phase 0 |
| R7 | Réglementation e-facturation mouvante | Snapshots complets + interface d'export, rien de plus |

---

## 4. Questions ouvertes — j'attends tes arbitrages

1. **Avoirs en Phase 1** : validé ? (Recommandation : oui, cf. §1.1.)
2. **Numérotation des devis** : la loi n'exige la continuité sans trou que pour les
   factures. Je propose d'utiliser **le même mécanisme** de séquence pour les devis
   (simplicité, un seul code path), sans garantie légale associée. OK ?
3. **ICE unique globalement ?** Un cabinet comptable peut gérer la société X pendant que
   X a son propre compte → deux `companies` avec le même ICE. Je propose : **pas de
   contrainte d'unicité**, validation de format seulement. OK ?
4. **Rôles par défaut** : je propose `owner`, `admin`, `accountant`, `sales`, `viewer`
   (libellés FR/AR à part). À valider ou amender.
5. **Auth SPA** : Sanctum en mode **cookies stateful** (SPA même domaine, CSRF géré) plutôt
   que tokens Bearer. Recommandé ; les tokens API viendront en Phase 4.
6. **Statut « en retard »** : dérivé (`due_date < today` et non payée) et jamais stocké,
   pour éviter un job de bascule et des états incohérents. OK ?
7. **Paiement multi-factures** : un chèque peut solder plusieurs factures → table
   `payment_allocations` (retenu dans le schéma). Confirmes-tu ce besoin pour ta cible ?
8. **Exercice fiscal ≠ année civile** : le schéma le permet (`fiscal_years` libre), mais
   le format `FAC-{YYYY}-…` est ambigu pour un exercice à cheval. Proposition : `{YYYY}` =
   année de la **date d'émission** du document. OK ?
9. **Chiffres sur les PDF arabes** : occidentaux (recommandé) ou hindous ?
10. **Repositories ciblés** (cf. §2.2) : validé ?
