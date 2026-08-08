# Impression des documents commerciaux — gabarits devis et facture

> Vues imprimables A4. Le gabarit du **devis** est calqué sur le modèle Word
> fourni par le client (`docs/devis modele.docx`), livré le 2026-08-05 ; celui
> de la **facture** en reprend le papier et les primitives, livré le
> 2026-08-06.

---

## 1. Périmètre

Deux routes, un même format A4 prêt à sortir sur l'imprimante ou à être
enregistré en PDF par le navigateur :

| Route | Gabarit |
|---|---|
| `/{locale}/quotes/{id}/print` | devis |
| `/{locale}/invoices/{id}/print` | facture |

L'entrée « Imprimer / PDF » y mène depuis deux endroits :
- le menu d'actions de la liste (`/quotes`, `/invoices`) ;
- le panneau de détail du document, à côté des actions de son cycle de vie.

Aucun des deux ne teste le type sur place : `printRoute(document)`
(`features/documents/schemas/document.ts`) fait autorité et rend `null` pour un
type sans gabarit — les avoirs, aujourd'hui. Deux conditions de type recopiées
dans deux menus divergent au premier type ajouté.

Chaque vue REFUSE un document d'un autre type (`print.wrongType`) : ouvrir une
facture dans le modèle du devis imprimerait des libellés faux.

Ces routes vivent dans le groupe **`app/[locale]/(print)/`**, dont la coquille
est nue : ni sidebar, ni barre du haut, ni palette ⌘K. Les URL ne changent pas
(un groupe de routes n'apparaît pas dans le chemin), mais la page ne porte plus
aucun champ de saisie ni contrôle de pilotage — recherche, calculatrice,
sélecteurs de langue et de thème — qui donnerait à croire qu'une valeur du
document se modifie là. Une pièce se consulte ; la correction passe par le
formulaire, et par un avoir pour un document émis (§3). Le chrome n'est pas
masqué en CSS : il n'est pas monté. La vue imprimable des **situations** a suivi
le même chemin, pour ne pas laisser deux coquilles d'impression concurrentes.

Seuls subsistent les boutons « Retour » et « Imprimer », en `print:hidden` :
ils pilotent la page, pas le contenu du document.

---

## 2. Décisions d'architecture

### 2.1 Impression navigateur, pas Gotenberg

La charte réserve **Gotenberg** au rendu PDF (§4). Il le reste — pour le
document *archivé* et *envoyé par e-mail*, où la fidélité doit être garantie
hors de tout navigateur et où le fichier produit devient une pièce conservée.

Tant que cette chaîne n'existe pas, une page imprimable rend le même service au
client sans faire dépendre l'impression d'un conteneur. Le gabarit HTML/CSS
écrit ici est précisément ce que Gotenberg consommera : le travail n'est pas à
refaire, il change seulement de moteur de rendu.

Même arbitrage que pour les situations, pour une raison différente : la
situation n'est *pas* une pièce opposable, le devis l'est — c'est la maturité de
la chaîne d'archivage qui manque, pas la légitimité du PDF.

### 2.2 Marque fixée en dur, mentions légales lues en base

**Décision produit du 2026-08-05, qui remplace l'arbitrage initial.** Cette
installation n'a qu'un seul exploitant : la marque affichée est **BCAT**
partout, quelles que soient les valeurs en base — logo `public/logo_v.jpeg`, nom
« BCAT », baseline « Bureau de Contrôle & Assistance Technique ».

Tout est réuni dans `lib/brand.ts`, jamais recopié dans les composants. Deux
raisons : une chaîne écrite en dur dans quinze fichiers ne se retire plus, et
revenir à l'identité de la société active demandera de remplacer les lectures de
`BRAND` par `active_company` — ce module est la carte de ce qu'il faudra alors
reconnecter.

La frontière est nette : les **mentions légales** (ICE, IF, RC, patente, CNSS,
RIB, adresse, téléphone) restent lues depuis la société active. Les figer serait
d'une autre nature — imprimer un ICE erroné sur un document commercial est une
faute, alors qu'un nom commercial est une affaire de présentation. Un champ
absent n'imprime pas de libellé vide.

Le logo est rendu par `next/image` avec `priority`, à **14 mm de haut**, soit
≈ 50 mm de large : le fichier est une bande de ratio ≈ 3,6 : 1, et le régler à
20 mm en occuperait 72, le quart d'une A4. `priority` n'est pas cosmétique ici :
une image en chargement paresseux peut ne pas être décodée au moment où le
navigateur compose la page à imprimer, et le devis sortirait sans logo.

Le fichier servi est **détouré** : le visuel d'origine
(`xpr-backend/public/logo_v.jpeg`, 1567 × 1004) est blanc aux deux tiers de sa
hauteur, si bien que toute contrainte de taille portait sur du vide et affichait
le glyphe trois fois trop petit. `public/logo_v.jpeg` en est la version rognée
(1317 × 367), respiration réintroduite. L'original reste en place côté backend.

La sidebar et l'en-tête mobile passent par `components/layout/brand-mark.tsx`,
qui dimensionne le logo **par la largeur** — sur une bande, contraindre la
hauteur dans une barre de 56 px donnerait une vignette de 15 px.

Le chrome sert **deux fichiers, un par thème** : `public/logo_v.jpeg` (encre
sombre sur blanc) et `public/icone_sombre.png` (blanc sur noir, 1712 × 660).
Deux visuels et non un filtre CSS : les deux sont opaques et portent leur propre
fond, et inverser le clair retournerait aussi la couleur d'accent du glyphe.
Chacun déduit sa hauteur de **son** ratio (≈ 3,6 : 1 contre ≈ 2,6 : 1) ; les
confondre en écraserait un.

La bascule est en CSS (`dark:hidden` / `hidden dark:flex`) et non par
`useTheme` : le hook rend `undefined` au premier passage, ce qui ferait
clignoter le mauvais logo à chaque chargement — sur le repère qui doit être le
plus stable de la page. La pastille suit (`bg-white` / `bg-neutral-950`) : c'est
elle qui a supprimé le rectangle blanc autour de la marque en thème sombre.

Le document IMPRIMÉ ignore cette bascule : `Letterhead` tire toujours
`BRAND.logo.light`, le papier est blanc. La classe `.dark` reste posée sur
`<html>` pendant l'impression — sans cette garde, un utilisateur en thème sombre
sortirait un bloc noir en tête de chaque devis.

Ce logo-ci ne porte plus la baseline en dur dans l'image, contrairement au
précédent : `BRAND.tagline` sous l'en-tête ne fait donc plus doublon.

Deux mentions du modèle n'avaient pas de colonne, ajoutées par
`2026_08_05_000004_add_letterhead_fields_to_companies` :

| Colonne | Rôle | Pourquoi pas ailleurs |
|---|---|---|
| `companies.tagline` | baseline sous la marque | ni la raison sociale ni le nom commercial : elle change sans toucher à l'immatriculation |
| `companies.bank_rib` | RIB porté sur le document | `bank_accounts` (§7, phase 2) sert le *rapprochement* et portera plusieurs comptes ; un document n'en affiche qu'un |

`CompanyResource` expose désormais l'identité légale complète. Ce n'est pas une
fuite : ce sont exactement les informations que l'entreprise imprime sur ses
devis. `companies.tagline` reste renseignée en base bien que l'impression lise
`BRAND.tagline` — elle redeviendra la source le jour où la marque se dé-fige.

**Reste à faire** : aucun écran ne permet encore de modifier l'identité de la
société. L'en-tête ne s'en ressent plus (la marque est fixée), mais le **pied de
page** dépend toujours de la base : un compte créé par inscription n'a que sa
raison sociale et sa forme juridique, ses mentions légales sortiront donc
incomplètes. Les données de démonstration (`DemoSeeder`) sont complètes, elles.

Le **logo par société** attend le module Files : `companies` prévoit un
`logo_file_id` qui n'est pas encore branché. Tant que la marque est fixée en
dur, la question ne se pose pas.

### 2.3 Correspondance des colonnes

Les huit colonnes du détail sont **déclarées une seule fois**, dans
`features/documents/components/print-line-table.tsx`, et servent à la fois le
`colgroup`, l'en-tête et le corps. Devis et facture rendent donc la **même
grille** : un client qui compare son devis et sa facture retrouve les mêmes
colonnes dans le même ordre.

C'est structurel et pas cosmétique : tant que chaque gabarit écrivait sa propre
liste de `<th>` puis sa propre liste de `<td>`, rien n'empêchait un `<td>` de
glisser sous la mauvaise colonne au premier ajout — ce qui était arrivé entre le
devis (6 colonnes) et la facture (8).

| Colonne | Source |
|---|---|
| `N°` | `document_items.position + 1` — le numéro de prix du bordereau BTP |
| `Désignation` | `label` en titre, puis `description` **découpée par ligne et pucée** |
| `U` | `unit` |
| `Qte` | `quantity` |
| `P U (HT)` | `unit_price_cents` |
| `P Total (HT)` | `subtotal_cents` — déjà **net de remise**, c'est la base d'imposition |
| `TVA` | `tax_rate` **figé sur la ligne** à la saisie (§3) |
| `Total TTC` | `total_cents` |
| `MAÎTRE D'OUVRAGE` | `client_name` (+ ICE quand il est connu) |
| `Objet` | `subject` |
| `RABAT, le …` | `issue_city`, à défaut `BRAND.defaultCity` |

Les largeurs sont dimensionnées sur le **contenu le plus long attendu** : un
montant à sept chiffres doit tenir sur une seule ligne. Le tableau est
`table-fixed` — sans largeur suffisante, un montant se replie ou déborde sur la
colonne voisine, et la grille devient illisible. Les cellules de montants sont
en `whitespace-nowrap` ; la désignation est la seule colonne qu'on accepte de
voir courir sur plusieurs lignes.

Un champ vide imprime le filet pointillé du modèle, à compléter à la main.

`subject` était accepté par l'API mais absent du formulaire des documents : il y
a été ajouté, sans quoi la ligne « Objet » n'aurait jamais pu être remplie.

### 2.4 La ville est saisie par document

`documents.issue_city` (migration `2026_08_05_000005`), nullable, exposée en
`issueCity`, saisie au champ « Ville » du formulaire.

Portée par le **document** et non par la société : un bureau de contrôle établit
ses devis là où se trouve le chantier, pas à son siège. Le modèle fourni le
montre en creux — son en-tête annonce Rabat quand son pied de page domicilie la
société à Oujda.

Nommée `issue_city` et non `city` : la table porte déjà `client_name`,
`client_ice` et `client_address`, et un `city` nu s'y lirait comme la ville du
CLIENT.

Le repli d'affichage (`RABAT`) vit dans `lib/brand.ts`, **pas** en valeur par
défaut de la colonne : écrit en base, il serait indistinguable d'une saisie
réelle, et les documents antérieurs au champ auraient l'air d'avoir été
renseignés.

### 2.5 Deux écarts assumés au modèle

1. **Le total TTC du modèle est faux** (231 000 + 34 650 = 265 650, il affiche
   196 350) et la somme en toutes lettres reprend ce montant erroné en le
   qualifiant de « HT ». La mise en page est reprise, l'arithmétique non : le
   montant arrêté est le **TTC réel**, et il est annoncé comme tel.
2. **La remise** n'existe pas dans le modèle. Quand un document en porte une,
   deux lignes s'ajoutent au pied (`Total brut HT`, `Remise`) : masquer une
   remise fausserait la lecture du document.

### 2.6 Somme en toutes lettres

`lib/amount-in-words.ts`, écrit pour l'occasion — mention obligatoire (§3), en
FR, AR et EN. Il part des **centimes entiers** : la partie entière et les
centimes sont séparés par `Math.trunc` et un modulo, jamais par une division
flottante.

Les accords ne sont pas décoratifs sur une mention légale et diffèrent par
langue : *quatre-vingts* mais *quatre-vingt-un* ; *dix millions **de** dirhams*
mais *dix mille dirhams* ; « zéro dirham » au singulier en français, pluriel en
anglais ; pluriel arabe de 3 à 10 seulement.

### 2.7 Un brouillon s'imprime

Un devis non émis n'a **pas de numéro** (§3) : il ne l'obtient qu'à l'émission.
On l'imprime quand même — une proposition commerciale circule avant d'être
émise — et l'écran le signale au-dessus du document, hors de la zone imprimée.

---

## 3. Feuille d'impression

Les règles vivent dans `app/globals.css` (bloc `@media print`), jamais dans les
écrans : un écran ne doit pas avoir à penser à l'impression pour bien
s'imprimer. Le document se contente de deux marqueurs :

- `.print-document` — masque la sidebar, la topbar et les boutons ;
- `.print-sheet` — corps d'un document à forme imposée : perd son décor d'écran
  (largeur A4, marges intérieures, anneau) et laisse `@page` tenir les marges,
  force les filets du tableau en noir franc, et interdit les coupures de page
  aux endroits qui ne le supportent pas (titre orphelin, sous-point scindé,
  bloc de totaux, encart de signature).

`@page` passe en `size: A4` — format du papier au Maroc, et la largeur sur
laquelle le gabarit est calé.

Les totaux sont dans un **tableau distinct** et non dans un `tfoot` : les
navigateurs répètent le pied de tableau sur chaque page imprimée, un devis de
deux pages afficherait donc deux fois son total.

---

## 4. Limite connue

Le papier à en-tête et les mentions légales s'impriment **une fois**, en tête et
en pied du flux, pas sur chaque page. Un devis de plusieurs pages ne répète que
l'en-tête du tableau des prestations. Répéter la lettre exigerait des éléments
en `position: fixed` calés dans les marges de `@page`, dont le placement varie
selon les navigateurs — à trancher quand Gotenberg entrera en jeu, où le moteur
de rendu sera unique et connu.

---

## 5. Ce que la facture porte en propre

Le papier (`Letterhead`, `LegalFooter`), les cellules de tableau, les champs à
filet pointillé et les lignes de totaux sont **partagés** :
`features/documents/components/print-primitives.tsx`, extrait du devis le jour
où la facture a repris sa structure. Deux copies d'une cellule divergent au
premier ajustement de bordure, et les pièces d'une même entreprise doivent
sortir de la même presse.

Ce qui n'est PAS partagé, parce qu'une facture n'est pas une proposition :

| Élément | Raison |
|---|---|
| Date d'échéance, conditions de règlement | fondent l'exigibilité ; un devis n'en a pas |
| Statut de paiement | lu sur `status`, libellé du même référentiel que les badges de la liste |
| Montant réglé / reste à payer | affichés **seulement si** `paidCents > 0` — sinon le « Total TTC » se lirait comme le solde dû |
| Mention d'échéance dépassée | sur le seul statut `overdue` |

La **TVA par ligne** n'est plus un écart : le devis porte désormais les mêmes
colonnes `TVA` et `Total TTC`. Le modèle Word du client n'en comptait que six —
c'est un troisième écart assumé, au bénéfice d'un devis dont chaque ligne
annonce le prix réellement payé.

Les taux imprimés sont ceux **figés sur chaque ligne** à la saisie (§3), jamais
une constante : une facture réimprimée doit ressortir identique après une
réforme du barème. Le récapitulatif par taux du pied vient de `taxSummary`,
ventilé par le serveur.

Le montant en toutes lettres porte le **TTC facturé**, pas le solde : c'est le
montant arrêté par la pièce ; l'acompte déjà reçu figure au pied de totaux.

### Non observable en l'état

`paidCents` vaut 0 sur toutes les factures de la base de démonstration, faute
d'encaissements rattachés. Le bloc « Montant réglé / Reste à payer » est donc
écrit mais jamais rendu tant que le module Cash n'alimente pas ce champ.
