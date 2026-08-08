# Module Conventions — contrats de convention & dépôts de dossier

> Livré le 2026-08-07. Source fonctionnelle : `docs/Contrat de convention modele.docx`.

## 1. Ce que le module couvre

Le **contrat de convention de contrôle et suivi** est la pièce que BCAT signe avec
le maître d'ouvrage avant d'ouvrir un chantier. Il fixe la mission (quels lots
sont contrôlés), les engagements des deux parties (articles 1 à 9) et les
honoraires forfaitaires avec leur échéancier (article 10).

Le **dépôt de dossier** est le suivi administratif qui s'ensuit : le dossier est
déposé auprès d'un organisme instructeur (commune, agence urbaine…), qui
l'instruit, puis le valide ou le rejette.

Trois entrées dans le produit :

| Écran | Route | Rôle |
|---|---|---|
| Liste des conventions | `/conventions` | recherche, filtre par état, accès à l'impression |
| Rédaction / correction | `/conventions/create`, `/conventions/{id}/edit` | formulaire + suivi du dossier |
| Contrat imprimable | `/conventions/{id}/print` | A4, articles 1 à 10, bloc de signature |
| Suivi des dépôts | `/deposits` | transverse, tous projets confondus |
| Fiche de dépôt | `/deposits/{id}/print` | document de suivi aux couleurs BCAT |

## 2. Décisions d'architecture

### 2.1 Table dédiée, pas un neuvième `DocumentType`

C'est l'arbitrage structurant, et il **s'écarte** du modèle « une table
`documents`, un discriminant `type` » (2026-07-21). Trois raisons, qui tiennent
toutes au fait qu'une convention n'est pas une pièce commerciale :

1. **Ni lignes, ni TVA.** Le moteur de `documents` s'articule autour de
   `document_items` et d'un récapitulatif de taxe par taux. Une convention porte
   un forfait TTC unique et un échéancier en pourcentages.
2. **Pas de numérotation fiscale.** Le n° de dossier (`0003439/AK/26`) est
   attribué par l'organisme instructeur, pas par `sequences`. Le faire passer par
   `DocumentNumberService` consommerait un numéro pour une pièce que la DGI
   n'attend pas.
3. **Champs propres.** Maître d'ouvrage, titre foncier, lots contrôlés, délai
   d'exécution n'ont aucun équivalent sur un devis.

Le lien avec le document d'origine reste explicite (`source_document_id`).

### 2.2 Le dépôt est une table fille, pas trois colonnes

Un même dossier est déposé **plusieurs fois** — un rejet suivi d'un nouveau
dépôt, un dossier déposé en parallèle à deux guichets. Aplatir cela sur
`conventions` n'aurait gardé que le dernier dépôt et effacé l'historique, qui est
précisément ce qu'on vient consulter.

### 2.3 L'échéancier est stocké en pourcentages

L'article 10 est rédigé ainsi (« 25 % du montant total »), et les montants s'en
déduisent sans jamais pouvoir diverger du forfait. `Convention::instalments()`
les calcule en centimes ; **le solde absorbe l'arrondi**, sinon la somme des
trois échéances ne ferait pas le total dû. Une contrainte CHECK
(`conventions_schedule_check`) impose la somme à 100 %.

### 2.4 Le texte contractuel n'est pas dans l'i18n

Les articles vivent dans `features/conventions/contract-text.ts`, en français,
quelle que soit la langue de l'interface. Ce n'est pas de l'interface, c'est un
**acte** : sa formulation engage juridiquement les deux parties. Le verser au
catalogue i18n obligerait à produire une version arabe et une version anglaise
que personne ici ne peut faire valider par un juriste — et un contrat mal traduit
ne se lit pas de travers, il s'oppose de travers. Une version arabe relève d'une
rédaction juridique, pas d'une tâche de localisation.

L'interface autour du contrat (boutons, libellés d'écran) reste traduite FR/AR/EN.

### 2.5 Immuabilité : la convention n'y est pas soumise

Le gel du §3 vise les pièces opposables à l'administration fiscale, numérotées
par `sequences`. Une convention est un contrat de droit privé : corriger un titre
foncier mal saisi avant le dépôt est le cours normal des choses. Elle reste donc
modifiable **même signée**.

Deux garde-fous subsistent :

- une convention **annulée** ne se modifie plus (`ConventionService::update()`) ;
- une convention **signée** ne se supprime pas — elle s'annule
  (`ConventionService::delete()` répond 409). L'engagement existe sur le papier
  signé par le client ; l'effacer de l'écran ne le défait pas.

## 3. Schéma de données

### `conventions`

| Colonne | Type | Note |
|---|---|---|
| `id` | uuid v7 | |
| `company_id` | uuid FK | discriminant tenant, RLS |
| `source_document_id` | uuid FK nullable | devis / facture d'origine, `nullOnDelete` |
| `partner_id` | uuid FK nullable | |
| `dossier_number` | varchar(40) nullable | **saisi**, index unique partiel par société |
| `status` | varchar(20) | `draft` / `sent` / `signed` / `cancelled` |
| `issue_city`, `issued_at` | | ville et date d'établissement |
| `owner_name`, `owner_ice`, `owner_rc`, `owner_address` | | identité **figée** du maître d'ouvrage |
| `project_description`, `project_address`, `project_title_deed` | | le TF identifie la parcelle |
| `lots` | jsonb | liste de libellés, article 1 |
| `execution_delay` | varchar(255) | article 9 |
| `total_cents`, `currency` | bigint / char(3) | forfait TTC (§7) |
| `advance_percent`, `visa_percent`, `completion_percent` | smallint | 25 / 25 / 50 par défaut |

Contraintes : `status` CHECK, ICE 15 chiffres, `total_cents >= 0`, somme des
pourcentages = 100, unicité partielle du n° de dossier. RLS appliquée.

### `file_deposits`

| Colonne | Type | Note |
|---|---|---|
| `convention_id` | uuid FK | `cascadeOnDelete` — un dépôt n'a pas de sens seul |
| `reference` | varchar(40) | référence du récépissé |
| `deposited_at` | date | |
| `organisation` | varchar(255) | commune, agence urbaine… |
| `status` | varchar(20) | `deposited` / `in_progress` / `validated` / `rejected` |
| `decided_at` | date nullable | CHECK : jamais antérieure au dépôt |

`company_id` y figure malgré la remontée possible par `convention_id` : le global
scope et la policy RLS filtrent sur cette colonne, une jointure ne saurait s'y
substituer (§5).

## 4. Endpoints

| Verbe | Route | Permission |
|---|---|---|
| GET | `/api/v1/conventions` | `conventions.view` |
| GET | `/api/v1/conventions/{convention}` | `conventions.view` |
| POST | `/api/v1/conventions` | `conventions.create` |
| POST | `/api/v1/conventions/from-document/{document}` | `conventions.create` |
| PATCH | `/api/v1/conventions/{convention}` | `conventions.update` |
| DELETE | `/api/v1/conventions/{convention}` | `conventions.delete` |
| GET | `/api/v1/deposits` | `deposits.view` |
| GET | `/api/v1/deposits/{deposit}` | `deposits.view` |
| POST | `/api/v1/conventions/{convention}/deposits` | `deposits.manage` |
| PATCH | `/api/v1/deposits/{deposit}` | `deposits.manage` |
| DELETE | `/api/v1/deposits/{deposit}` | `deposits.manage` |

`from-document` est déclarée **avant** `conventions/{convention}` : sans ce
placement, `{convention}` capturerait « from-document » et le transfert finirait
en 404 sans rien dire de la cause.

La création d'un dépôt est **imbriquée** sous la convention : le rattachement
vient du chemin, jamais du corps de la requête (§5.3). La lecture, la correction
et le retrait s'adressent au dépôt lui-même, déjà résolu sous le scope tenant.

Deux permissions seulement pour les dépôts, contre quatre pour les conventions :
le dépôt est un suivi, pas un engagement — rien ne distingue le risque d'en
corriger un de celui d'en supprimer un.

## 5. Le transfert « Transférer en Contrat de Convention »

Point d'entrée : le menu d'actions des **devis** et des **factures**, dans la
liste comme dans le panneau de détail.

Ce que le transfert fait — `ConventionDraftingService::fromDocument()` :

| Champ de la convention | Origine |
|---|---|
| `owner_name` | `client_name` du document (identité figée) |
| `owner_ice` | `client_ice`, à défaut l'ICE de la fiche tiers |
| `owner_rc` | fiche tiers — **aucun document commercial ne porte le RC** |
| `owner_address` | `client_address`, à défaut l'adresse du tiers |
| `project_description` | `subject` du document |
| `project_address` | `client_address` — proposition à relire, pas une vérité |
| `project_title_deed` | néant : à saisir |
| `total_cents` | TTC du document |
| `lots` | les 4 lots du modèle client, à ajuster |
| `advance/visa/completion` | 25 / 25 / 50 |

Ce que le transfert **ne fait pas** : il ne consomme pas le document source.
Contrairement à la conversion devis → facture, le devis reste `sent` et demeure
convertible — c'est même l'ordre normal : on signe la convention, puis on facture
l'avance.

Refusé (409) sur : un type autre que devis / facture, un document **annulé** ou
**refusé**. Accepté sur un brouillon (la convention précède souvent l'émission)
et sur un devis **converti** (l'affaire est conclue, c'est le moment).

Le premier **dépôt** reporte sa référence sur `conventions.dossier_number` si
celui-ci est vide ; les dépôts suivants ne l'écrasent pas — un second dépôt reçoit
une nouvelle référence, mais le dossier que le contrat cite reste le premier.

## 6. Impression

`/conventions/{id}/print` reprend le papier à en-tête des documents commerciaux
(`Letterhead` / `LegalFooter`) : le logo et la baseline viennent de `BRAND`, les
**mentions légales** (RC, CNSS, patente, RIB, adresse) sont lues sur la société
active — un RIB erroné sur un acte signé est une faute, pas un détail de
présentation.

Deux valeurs ont été ajoutées à `lib/brand.ts` :

- `representative` (« EL KIRAT ANAS ») — `companies` n'a pas de notion de
  représentant légal ; une colonne se justifiera le jour où plusieurs personnes
  signent ;
- `bankName` (« BANK OF AFRICA ») — seul le nom manque au schéma, le RIB reste lu
  en base.

Impression **navigateur** et non PDF serveur, comme les devis et situations :
Gotenberg (§4) entrera en jeu pour le document archivé et envoyé par e-mail.

La fiche de dépôt porte une mention explicite : elle est un document de suivi,
seul le récépissé de l'organisme fait foi. BCAT n'a pas qualité pour émettre un
récépissé.

## 7. Tests

`tests/Feature/Conventions/` — 19 cas, tous verts :

- `ConventionTest` : échéancier par défaut, refus d'une somme ≠ 100 %, refus d'un
  échéancier transmis à moitié, absorption de l'arrondi par le solde, transfert
  depuis un devis (maître d'ouvrage, RC venu du tiers, projet, honoraires),
  devis laissé intact, refus sur document annulé et sur avoir, correction d'une
  convention signée, refus de suppression d'une convention signée, **isolation
  tenant** (liste et accès direct).
- `FileDepositTest` : report du n° de dossier au premier dépôt et non au second,
  effacement de la date de décision quand le dossier repasse en instruction,
  refus d'une décision antérieure au dépôt, filtre par convention, **refus de
  rattacher un dépôt à la convention d'une autre société**, isolation tenant.

## 8. Reste à faire

- **Jeu de démonstration** : `WorkspaceDemoDataService` ne crée aucune convention.
  Un compte de démo n'a donc rien à montrer sur ces deux écrans, qui s'ouvrent sur
  leur état vide.
- **Version arabe du contrat** : cf. §2.4 — rédaction juridique, pas localisation.
- **PDF serveur** (Gotenberg) : commun à tous les documents imprimables du dépôt.
