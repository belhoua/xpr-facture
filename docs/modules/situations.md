# Module Situations

> État périodique d'une créance client — avancement de chantier, décompte
> mensuel. Livré le 2026-08-05.

---

## 1. Ce qu'est une situation, et ce qu'elle n'est pas

Une situation atteste d'un **montant dû à une date**, et de ce qui en a déjà été
réglé. Elle sert au suivi d'un chantier ou d'un abonnement de prestation : « au
31 octobre, 500 000 MAD dus, 200 000 encaissés ».

Elle **n'est pas une pièce fiscale**. Elle ne porte aucune TVA, n'ouvre aucun
droit à déduction, et n'est pas opposable à la DGI. C'est cette qualification
qui justifie les trois écarts assumés décrits plus bas — chacun serait
inacceptable sur une facture.

---

## 2. Décisions d'architecture

### 2.1 Réutilisation de `documents` plutôt qu'une table dédiée

Une situation porte un numéro, une date, un tiers, un montant, un état de
règlement — soit exactement l'en-tête d'un document commercial. Une table
`situations` aurait dupliqué la numérotation atomique, l'instantané légal du
client, les policies RLS et le scope tenant, pour n'ajouter aucune donnée
nouvelle.

Elle rejoint donc les huit autres types, discriminée par `type = 'situation'`,
dans la ligne de l'arbitrage du 2026-07-21 (« une table unique, pas une table
par type »).

### 2.2 Montant global, sans lignes

La règle générale du moteur est que **le total est une conséquence des lignes**,
recalculée à chaque écriture par `DocumentCalculator` — jamais une donnée
d'entrée, sans quoi on pourrait facturer un montant sans rapport avec le détail
affiché.

La situation inverse la relation : son montant est saisi en en-tête. L'exception
est portée par `DocumentType::hasGlobalAmount()` et **bornée à ce seul type par
l'enum** — jamais par un drapeau transmis par l'appelant, qui permettrait de
court-circuiter le calcul sur une facture. `subtotal_cents = total_cents`,
`tax_cents = discount_cents = 0`.

Conséquence : `items` est refusé (422) sur une situation. Accepter des lignes
créerait deux sources de vérité pour le montant.

### 2.3 L'état est saisi, avec une déduction par défaut

Quatre états sont proposés :

| `status` | Libellé | Teinte | Déductible ? |
|---|---|---|---|
| `sent` | Non payé | rouge | oui — `paid_cents == 0` |
| `in_progress` | En cours | bleu | **non** |
| `partial` | Partiel | jaune | oui — `0 < paid_cents < total_cents` |
| `paid` | Payé | vert | oui — `paid_cents >= total_cents` (et `total > 0`) |

**L'utilisateur choisit**, et son choix prime. À défaut de `status` transmis,
`Document::settlementStatus()` déduit l'état de l'avance — ce qui garde les
appels antérieurs à cette évolution valides.

`in_progress` est le seul état que rien ne permet de déduire : aucun montant ne
dit qu'un chantier est ouvert. C'est précisément ce qui justifie de laisser la
main à l'utilisateur plutôt que de tout dériver des chiffres.

**Conséquence à connaître.** L'état et les montants peuvent désormais se
contredire — rien n'empêche d'afficher « payé » sur une situation dont
`paid_cents` vaut 0. C'est le prix d'un état saisi ; les indicateurs de l'écran
client, eux, restent calculés sur les **montants** et disent donc toujours la
vérité comptable. Aucune conséquence fiscale : la situation ne porte pas de TVA
et n'alimente aucune déclaration.

#### Réalignement automatique, et ce qu'il épargne

`refreshSettlementStatus()` recalcule l'état quand les montants changent :
corriger un total de 5 000 à 3 000 sur 3 000 encaissés fait basculer la ligne de
« partiel » à « payé », plutôt que d'afficher un badge qui contredit les chiffres
de sa propre ligne.

Trois cas y échappent :
- un `status` explicitement transmis — il prime, c'est le principe même ;
- les états **terminaux** (`cancelled`) ;
- **`in_progress`** — sans cette garde, un PATCH ne portant que l'objet
  ramènerait la situation à « non payé », et l'utilisateur perdrait un état posé
  sciemment en corrigeant tout autre chose.

Le front envoie donc `status` à **chaque** écriture, création comme correction.

Un total à zéro sans encaissement reste `sent` et non `paid` : il n'y a rien à
régler, ce n'est pas un règlement.

#### Pourquoi `in_progress` et non `draft`

`draft` était disponible et aurait évité une migration. Il a été écarté : ce
n'est pas un état d'avancement mais un état de **rédaction**. Tout le moteur s'en
sert pour dire « ce document n'a pas de numéro » — `settlementStatus()` le
renvoie quand `isIssued()` est faux, `isEditable()` en dérive, l'émission le
consomme. Or une situation est numérotée dès sa création : la marquer `draft` la
ferait passer pour non émise partout où le code interroge son état.

> **Note d'affichage.** `sent` prend le rouge de `overdue` dans ce module
> (`SituationStatusBadge`). Sur une facture, « envoyée » est neutre — l'échéance
> court, rien n'est anormal. Sur une situation, le même code signifie « aucune
> avance encaissée », qui est la préoccupation de l'écran. Décision d'affichage
> seulement : la donnée et l'enum sont inchangées.
>
> `in_progress` reprend le **bleu de `sent`** plutôt qu'une teinte inédite :
> « en cours » et « envoyé » disent tous deux qu'une affaire suit son cours sans
> rien réclamer, et §11 impose une sémantique de statut stable — pas une couleur
> de plus par écran.

---

## 3. Les trois écarts assumés

Ces écarts ont été **explicitement validés** le 2026-08-05. Ils tiennent tous à
la même prémisse : la situation n'est pas opposable à l'administration fiscale.

### 3.1 Numérotation dès la création, sans étape d'émission

`DocumentType::numbersOnCreate()`. Tout autre document naît brouillon et acquiert
son numéro par une action délibérée — l'émission est un acte engageant, la rendre
implicite ouvrirait la porte à des numéros consommés par une double soumission.

La situation se numérote à l'enregistrement (`SIT-{YYYY}-{0000}`), via le même
`DocumentNumberService::allocate()`, dans la transaction de création. `issued_at`
est renseignée d'office si absente : elle désigne l'exercice, donc la séquence.

### 3.2 Modifiable après numérotation

`DocumentType::freezesOnIssue()` renvoie `false`. Une situation numérotée reste
éditable et supprimable ; la correction ne passe pas par un avoir.

> **Rectification du 2026-08-06.** Ce paragraphe disait « seule brèche dans
> l'immuabilité du §3 ». Ce n'est plus vrai : la **facture** a rejoint la
> situation dans cette exception, sur décision de l'exploitant. Les deux cas
> n'ont rien à voir — la situation n'est pas opposable à l'administration
> fiscale, la facture l'est. Voir `docs/modules/documents-immuabilite.md`.

`assertEditable()` ferme malgré tout la porte sur un état **annulé** : terminal
pour tous les types, rouvrir effacerait la trace de l'annulation.

### 3.3 Séquence à trous

Corollaire direct de 3.2 : supprimer une situation consomme un numéro qui ne sera
pas réattribué. `SIT-2026-0002` peut manquer entre `0001` et `0003`.

Ce serait **rédhibitoire sur `FAC-`** (§3 : « séquence continue, sans trou, sans
réutilisation ») et reste sans portée ici, la continuité n'étant exigible que sur
les pièces opposables.

---

## 4. Schéma de données

Migration `2026_08_05_000002_add_situations_to_documents`.

| Colonne | Type | Rôle |
|---|---|---|
| `subject` | `varchar(255) NULL` | Objet (« Situation du mois d'octobre »). En-tête et non ligne de détail : la liste l'affiche et le recherche sans jointure. |
| `paid_cents` | `bigint NOT NULL DEFAULT 0` | Montant réglé. **Total dénormalisé**, au même titre que `tax_cents` : saisi à la main aujourd'hui, il sera recalculé depuis les règlements quand le module Encaissements arrivera — sans migration ni changement de contrat. |

`NOT NULL DEFAULT 0` plutôt que nullable : « aucune avance » et « avance nulle »
sont la même chose, et un `NULL` obligerait chaque calcul de reste à dû à le
coalescer.

**Contraintes CHECK** (posées en base, pas seulement dans le FormRequest — elles
protègent aussi les écritures futures du module Encaissements, qui ne passeront
pas par le même chemin de validation) :

- `documents_paid_positive_check` : `paid_cents >= 0`. Une avance négative n'est
  pas un remboursement, c'est une saisie fausse ; le remboursement se traite par
  avoir.
- `documents_paid_not_above_total_check` : `paid_cents <= total_cents`.

**Index** : `(company_id, type, partner_id)`. L'écran « par client » filtre sur
ces trois colonnes ; l'index existant s'arrêtait à `(company_id, partner_id)` et
aurait aussi fait remonter les factures.

Les contraintes d'énumération `documents_type_check` et `sequences_doc_type_check`
sont étendues au 9ᵉ type. La migration **ouvre la séquence `situation` sur
l'exercice courant de chaque société existante** — sans cette reprise, seules les
sociétés créées ensuite en disposeraient.

---

## 5. API

Aucun endpoint nouveau pour le CRUD : les situations passent par `/documents`,
discriminées par `type=situation`.

| Méthode | Route | Notes |
|---|---|---|
| `GET` | `/api/v1/documents?type=situation` | Filtres `search`, `status`, `partnerId`, `from`, `to` |
| `GET` | `/api/v1/documents/summary?type=situation&partnerId=…` | **Nouveau.** Totaux des 4 indicateurs |
| `GET` | `/api/v1/documents/{id}` | |
| `POST` | `/api/v1/documents` | `type`, `partnerId`, `subject`, `totalCents`, `paidCents` |
| `PATCH` | `/api/v1/documents/{id}` | Autorisé même numérotée (§3.2) |
| `DELETE` | `/api/v1/documents/{id}` | Soft delete, même numérotée (§3.3) |

`GET documents/summary` est déclaré **avant** `documents/{document}` : Laravel
retient la première route qui correspond, et `{document}` capturerait « summary »
comme un identifiant.

Endpoint d'agrégats séparé et non bloc `meta` sur la liste : les totaux ne
changent pas d'une page à l'autre, les recalculer à chaque page serait gratuit.
Les deux partagent le même socle de filtrage (`DocumentService::filtered()`) —
si chacun appliquait les siens, une divergence ferait afficher des indicateurs
qui ne décrivent plus les lignes en dessous.

### Champs exposés (`DocumentResource`)

`subject`, `paidCents`, `remainingCents`. Le solde est **calculé** et non
stocké ; `remainingCents()` le borne à zéro par sécurité d'affichage, la
contrainte base garantissant déjà qu'il ne peut être négatif.

### Validation

| Champ | Situation | Autres types |
|---|---|---|
| `partnerId` | **requis** | optionnel |
| `subject` | **requis** | optionnel |
| `totalCents` | **requis** | ignoré |
| `paidCents` | optionnel (défaut 0) | ignoré |
| `status` | optionnel (défaut : déduit) | ignoré |
| `items` | **interdit** (422) | accepté |

`status` n'accepte que les valeurs de
`DocumentStatus::manuallyAssignableFor(Situation)` — soit `sent`,
`in_progress`, `partial`, `paid`. Sont refusés en 422 : `draft` (le document est
numéroté), `overdue` (pas d'échéance opposable), `cancelled` et `converted`
(endpoints dédiés, avec leurs propres règles), et toute valeur inconnue.

Comme les montants, il est **ignoré** sur les autres types : l'état d'une facture
est la conséquence de son cycle, et l'accepter en entrée permettrait d'en créer
une « payée » sans qu'aucun règlement n'ait eu lieu. Le service refiltre par type
(`requestedStatus()`), y compris pour les appels qui ne passent par aucune
validation HTTP — seeders, conversion.

`partnerId` est obligatoire parce que l'écran « par client » agrège sur
`partner_id` : une situation à client libre y serait invisible, donc absente des
totaux. Un reste à payer faux vaut moins qu'un champ obligatoire.

`totalCents` et `paidCents` sont **ignorés** hors situation et non rejetés —
c'est le traitement déjà réservé à `status`, `number` et `subtotalCents`. Le
filtrage se fait sur le type du document **persisté**
(`DocumentWriteService::globalAmountColumns()`) : un `totalCents` posté sur une
facture n'a aucun chemin vers ses totaux.

Le plafond « avance ≤ total » est vérifié à deux niveaux, parce qu'aucun seul ne
suffit :

1. `DocumentStoreRequest::withValidator()` → **422** quand les deux champs sont
   présents (message rattaché au champ, exploitable par React Hook Form) ;
2. `DocumentWriteService::assertSettlementWithinTotal()` → **409** sur un PATCH
   partiel, où le FormRequest n'a qu'un champ et rien à comparer ;
3. la contrainte CHECK, dernier filet.

---

## 6. Frontend

| Route | Écran |
|---|---|
| `/situations` | 4 indicateurs + liste (situations **et** factures), recherche, filtre de statut, de chantier, plage de dates |
| `/situations/create` | Formulaire de saisie |
| `/situations/[id]/edit` | Correction |
| `/situations/[id]/print` | Vue imprimable d'une situation |
| `/situations/by-client` | Choix du client |
| `/situations/by-client/[clientId]` | 4 indicateurs + détail filtrable + impression |

Des **pages** et non des modales, contrairement aux factures : chaque écran a son
URL, donc survit au rechargement et se partage par lien.

Le module réutilise le contrat `Document` du serveur en lecture — c'est la même
ressource. `features/situations/schemas/situation.ts` ne porte que le schéma de
**saisie**, qui lui est propre.

### Périmètre des deux écrans de suivi (2026-08-24)

`/situations` et `/situations/by-client/[clientId]` portent sur les **mêmes
types de pièces** : `SETTLEMENT_DOCUMENT_TYPES = ["situation", "invoice"]`,
déclaré une seule fois dans `schemas/situation.ts`. Le devis en est exclu — il
propose, il ne crée aucune créance, et l'additionner gonflerait le « reste à
payer » d'un montant que personne ne doit.

La liste `/situations` ne montrait que les situations jusqu'à cette date. Une
facture née d'un devis transféré n'y figurait donc jamais : sur un dossier qui
ne travaille qu'au devis, l'écran restait vide et ses indicateurs à zéro alors
que des créances existaient. Ce qu'un client doit ne dépend pas du type de pièce
par lequel on le lui a demandé.

Deux conséquences de ce mélange, tenues dans `situations-view.tsx` :

- **les actions se lisent sur le type de la ligne.** Une situation se corrige et
  se supprime depuis l'écran ; une facture, non — elle est gelée par son numéro
  (§3), sa correction passe par un avoir, et son cycle de vie vit sur
  `/invoices`. Un crayon sur une facture mènerait à un formulaire de situation
  qui refuse son type (`SituationEditor`, garde déjà en place) ;
- **l'impression passe par `printRoute(document)`**, la table type → gabarit du
  module Documents : la facture s'imprime en facture, pas dans la feuille de
  suivi d'une situation. Le clic sur la ligne suit la même règle — formulaire
  pour une situation, facture imprimable pour une facture, seule vue d'une
  facture qui ait sa propre URL.

Les quatre indicateurs des deux écrans viennent de `/documents/summary` et non
d'une somme des lignes affichées : la liste est paginée, additionner la page
donnerait un total faux dès la 26ᵉ pièce — et faux sans le dire. Liste et
agrégats partagent les mêmes filtres, ce qui garantit que les chiffres du haut
décrivent exactement les lignes du bas.

### Navigation

`NavItem` gagne un champ `children`, consommé par `app-sidebar.tsx`. L'entrée
parente reste un **lien** : le chevron déplie, il ne capture pas le clic — un
menu qu'on ne peut qu'ouvrir imposerait deux gestes là où un suffisait.

L'état déplié est **dérivé** (`manuallyToggled ?? containsActive`) et non
synchronisé par un effet : il ne peut pas se retrouver en retard d'un rendu sur
l'URL. L'entrée active se résout par « le plus spécifique gagne » — sur
`/situations/create`, seule « Ajouter » s'allume, pas « Liste des situations »
dont le `href` est pourtant un préfixe.

`NAV_ITEMS` (palette ⌘K) aplatit les enfants en **dédupliquant par `href`** :
`/situations` figure à la fois comme parent et comme enfant.

### Impression

`window.print()` sur la page, et non un PDF Gotenberg : la situation est un état
des lieux consultable, pas une pièce à archiver — Gotenberg reste réservé aux
documents qui engagent (§4).

La feuille `@media print` (`app/globals.css`) est **globale et non par écran** :
elle force fond blanc / texte noir (le thème sombre imprimerait une page noire,
ou du texte clair sur blanc puisque les navigateurs n'impriment pas les fonds),
masque la coquille applicative, libère `overflow` et `height` sur `main` pour que
le flux déborde sur plusieurs pages, et répète `thead` sur chaque page. Les
écrans marquent simplement leur conteneur `.print-document`.

La vue imprimable porte une **mention explicite** : sans elle, une situation
imprimée pourrait être prise pour une facture.

---

## 7. Tests

`tests/Feature/Documents/SituationTest.php` — 36 tests, 138 assertions.

Couverture : numérotation à la création · les 4 cas de déduction du statut ·
absence de TVA · refus des lignes · champs obligatoires · refus de l'avance
excessive (422 et 409) · édition et suppression après numérotation · fermeture
après annulation · agrégats · **agrégats au-delà d'une page** (30 situations, où
une somme côté client serait fausse) · filtre de dates · recherche par objet ·
**isolation tenant** (§5.6) · non-régression : montant et état ignorés sur
facture.

Sur l'état saisi : les 4 valeurs acceptées · la **primauté du choix sur la
déduction** (une situation soldée déclarée « en cours » le reste) · la
déduction préservée quand rien n'est transmis · **`in_progress` survit à un
PATCH portant sur autre chose** · le changement d'état en cours de vie · le refus
des 5 valeurs hors matrice.

Deux assertions de `DocumentNumberingTest` ont été ajustées (3 → 4 séquences
provisionnées) : `provisionedAtSignup()` inclut désormais la situation.

---

## 8. Dette et suites

- **`paid_cents` sera recalculé** depuis les règlements à l'arrivée du module
  Encaissements. Le contrat d'API ne changera pas : le champ reste un total
  absolu, ce qui le rend idempotent (un « +1 000 » rejoué par une double
  soumission gonflerait le montant sans trace).
- `DocumentWriteService::recordSettlement()` existe et est testé indirectement,
  mais **n'a pas d'endpoint** : l'avance se saisit aujourd'hui par le formulaire.
  Il est prêt pour le module Encaissements.
- Aucun test Vitest / Playwright sur ces écrans : l'outillage frontend
  (P0-18) n'est pas encore installé.
