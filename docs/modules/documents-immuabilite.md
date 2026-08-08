# Immuabilité des documents — état réel de la règle

> Note de décision. Le §3 du `CLAUDE.md` pose l'immuabilité comme non
> négociable ; ce fichier consigne où le code s'en écarte, pourquoi, et comment
> revenir en arrière. Il décrit l'ÉTAT, la charte décrit la CIBLE.

---

## 1. La règle telle qu'elle est appliquée aujourd'hui

**Deux actes, deux prédicats.** Modifier laisse une pièce ; supprimer consomme
un numéro qui ne sera jamais réattribué. Les deux méthodes restent distinctes
bien qu'elles portent, depuis le 2026-08-07, exactement les mêmes types : la
coïncidence est fortuite, et les fusionner rendrait la prochaine ouverture
d'édition silencieusement destructrice.

| Acte | Prédicat (`Accounting\Enums\DocumentType`) | Garde (`DocumentWriteService`) |
|---|---|---|
| `update()` | `freezesOnIssue()` | `assertEditable()` |
| `delete()` | `deletableOnceIssued()` | `assertDeletable()` |

| Type | Modifiable une fois numéroté ? | Supprimable une fois numérotée ? | Depuis |
|---|---|---|---|
| Proforma, BC, BL, bordereau, facture d'achat | non | non | origine |
| Situation | **oui** | **oui** | 2026-08-05 |
| Facture | **oui** | **oui** | 2026-08-06 |
| Devis | **oui** | **oui** | 2026-08-06 / **2026-08-07** |
| **Avoir** | **oui** | **oui** | **2026-08-07** |

> **Plus aucune pièce commerciale n'est figée après émission.** Les cinq types
> qu'exploite le produit — devis, facture, avoir, situation — sont tous
> modifiables et supprimables une fois numérotés. Le §3 du `CLAUDE.md` décrit
> désormais une cible dont le code s'est entièrement écarté, sur décisions
> successives de l'exploitant.

Ce qui subsiste, pour tous les types :

- **l'annulation ne se rouvre jamais.** C'est le seul état terminal issu d'un
  acte délibéré, avec son endpoint et sa permission propres
  (`documents.cancel`) ; la rouvrir effacerait la trace de l'annulation
  elle-même, et `cancel` ne signifierait plus rien. Borne non négociable ;
- **un état terminal ferme la SUPPRESSION**, y compris pour le devis que
  l'édition rouvre désormais (cf. §2 ter) ;
- la suppression est un **soft delete** : la ligne demeure en base avec son
  `deleted_at`, seule l'application cesse de l'afficher. C'est la seule
  atténuation des trous de séquence.

---

## 2. La levée du gel sur les factures (2026-08-06)

**Décision de l'exploitant, prise contre l'avis porté par ce dépôt**, après
présentation explicite des trois options (garder le gel et exposer
annulation/avoir ; autoriser la modification seule ; tout lever). La troisième a
été retenue.

### Ce que cela coûte

1. **La séquence `FAC-` peut présenter des trous.** Supprimer une facture
   numérotée consomme définitivement son numéro : `sequences` n'est jamais
   rembobinée. L'article 145 du CGI marocain exige une numérotation continue ;
   un contrôle peut s'en saisir.
2. **Une facture remise au client peut être modifiée après coup** sans laisser
   d'avoir, donc sans trace comptable de la correction. Le mécanisme d'avoir
   reste disponible mais n'est plus la seule voie.
3. **L'index unique partiel `(company_id, number)` ne retient que les lignes
   vivantes** (`WHERE number IS NOT NULL AND deleted_at IS NULL`). Le numéro
   d'une facture supprimée redevient donc techniquement libre — sans
   conséquence tant que rien ne rembobine la séquence, ce qu'aucun code ne fait.

### Ce qui est verrouillé par des tests

`tests/Feature/Documents/DocumentCrudTest.php` encadre la nouvelle frontière —
c'est le garde-fou contre une extension silencieuse de la brèche :

| Test | Attendu |
|---|---|
| modifie une facture émise | 200, le numéro ne bouge pas |
| supprime une facture émise et troue la séquence | 204, ligne soft-deleted conservée, numéro non réattribué |
| modifie un devis émis, lignes comprises | 200, le numéro ne bouge pas |
| modifie un avoir émis | 200, le numéro `AV-` ne bouge pas |
| modifie un devis converti | 200, l'état reste `converted` |
| supprime un devis émis | 204, ligne soft-deleted conservée |
| supprime un avoir émis | 204, ligne soft-deleted conservée |
| **refuse** de modifier un devis annulé | 409 |
| **refuse** de supprimer un devis converti | 409 |
| **refuse** de modifier une facture annulée | 409 |

### Comment révoquer

Un seul caractère, dans `DocumentType::freezesOnIssue()` : rétablir
`return $this !== self::Situation;`. Puis reprendre les six tests ci-dessus et
`isEditable()` / `isDeletable()` côté frontend, qui ne font que refléter la
règle du serveur.

---

## 2 bis. La levée du gel sur les devis (2026-08-06)

Demandée par l'exploitant, et **de loin la plus défendable des trois** : un
devis n'atteste rien auprès de la DGI, il PROPOSE. Le renégocier est le cours
normal des affaires, et l'alternative — refaire un devis à chaque ajustement de
prix — brûle un numéro de la séquence `DEV-` par échange commercial.

Ce qu'elle coûte quand même : un devis **déjà envoyé peut être modifié sans que
le client le sache**, et deux versions d'un même `DEV-` peuvent circuler. Le
numéro ne changeant pas, seul `updated_at` en garde trace — le module `Audit`
prendra le relais quand il journalisera les écritures.

Deux limites étaient alors explicites et voulues — la suppression fermée, les
états terminaux clos. **Les deux ont été levées le lendemain** (§2 ter) ; elles
sont conservées ici parce qu'elles disent ce que la décision du 2026-08-06
recouvrait, et ce qu'elle ne recouvrait pas.

---

## 2 ter. Devis et avoir : suppression, et réouverture des devis terminaux (2026-08-07)

Demandé par l'exploitant, **après que le coût de chaque levée lui a été exposé et
qu'il a maintenu sa demande**. Trois changements distincts.

### a. Suppression des devis émis

La moins lourde. Un devis n'atteste rien auprès de la DGI : un trou dans `DEV-`
n'est opposable à personne.

### b. Suppression des avoirs émis

**La plus lourde de toutes les levées consenties à ce jour.** L'avoir *est* une
pièce fiscale — c'est l'instrument par lequel le §3 fait corriger une facture.
La séquence `AV-` peut désormais présenter des trous, un contrôle les verra, et
rien en base ne dira ce qui occupait le numéro manquant. Le soft delete conserve
la ligne, mais hors de portée de l'application : personne ne la consultera sans
requête SQL directe.

Combinée à la levée du gel des avoirs (2026-08-07, éditions), elle signifie
qu'une correction de facture peut être **réécrite ou effacée** sans qu'aucune
pièce n'atteste l'écart — alors que c'est cet écart qu'un contrôle vient
chercher.

### c. Réouverture des devis en état terminal

`DocumentWriteService::reopensWhenTerminal()` : un devis `converted` ou
`refused` redevient modifiable.

- **converti** — le devis a produit une facture. Le modifier le fait diverger
  d'elle sans que rien ne le signale : le client peut détenir un `DEV-` et un
  `FAC-` qui ne portent plus les mêmes lignes, alors que `parent_document_id`
  continue d'affirmer que l'un découle de l'autre. En litige, c'est la pièce du
  client qui parle. L'état, lui, **reste** `converted` : la levée porte sur le
  contenu, elle ne ressuscite pas le cycle de vie ;
- **refusé** — bien plus léger : un devis refusé n'engage rien, aucune pièce
  fiscale n'en dépend.

### La seule divergence qui subsiste entre les deux prédicats

Un devis converti se **modifie** mais ne se **supprime** pas. L'effacer couperait
le lien de parenté, et sa facture perdrait la trace de ce dont elle découle —
question qu'on pose précisément en litige. C'est aujourd'hui le seul point où
`assertEditable()` et `assertDeletable()` ne répondent pas la même chose, et la
raison pour laquelle les deux méthodes restent séparées.

### Comment révoquer

- suppressions : retirer `self::Quote` et `self::CreditNote` de
  `deletableOnceIssued()` ;
- édition des avoirs : retirer `self::CreditNote` de `freezesOnIssue()` ;
- réouverture : faire rendre `false` à `reopensWhenTerminal()`.

Puis reprendre les tests de `DocumentCrudTest.php` et les deux prédicats
frontend, qui ne font que refléter le serveur.

---

## 3. Côté interface

`features/documents/schemas/document.ts` **reflète** la règle du serveur, il ne
la décide pas : la retirer là sans la révoquer dans l'enum masquerait l'écriture
sans l'empêcher. Deux prédicats y répondent aux deux du backend —
`isEditable()` pour `freezesOnIssue()`, `isDeletable()` pour
`deletableOnceIssued()`.

Deux conséquences visibles :

- le menu « … » propose **Modifier** et **Supprimer** sur un devis, une facture,
  un avoir ou une situation, quel que soit leur statut hors terminal. Un devis
  `converted` ou `refused` garde **Modifier seul** — la suppression y reste
  fermée. L'entrée désactivée « Émis : non modifiable » ne s'affiche plus que sur
  une pièce annulée, ou sur les types jamais ouverts (proforma, bon de commande,
  bon de livraison, bordereau, facture d'achat) ;
- la confirmation de suppression a **deux textes**. Sur un brouillon, elle
  signale qu'aucun trou n'est laissé. Sur une pièce numérotée, elle nomme le
  numéro perdu et rappelle que la correction habituelle est un avoir. Un texte
  unique finirait par mentir dans l'un des deux cas.

Le formulaire d'édition recharge le **détail** du document avant de se
pré-remplir : la liste n'expose pas les lignes (`items`), et s'en contenter
ouvrirait un formulaire vide dont l'enregistrement effacerait toutes les
prestations. Le cache TanStack absorbe le coût quand l'édition part du panneau
de détail.

---

## 4. Les transferts (devis → facture, facture → avoir)

Deux endpoints, un seul service : `DocumentConversionService`. Ce sont les deux
seuls chemins par lesquels un document en engendre un autre.

| Transfert | Endpoint | Effet sur la source |
|---|---|---|
| Devis → facture | `POST /documents/{id}/convert` | le devis passe `converted`, état **terminal** — mais son contenu reste modifiable depuis le 2026-08-07 (§2 ter) |
| Facture → avoir | `POST /documents/{id}/credit-note` | **aucun** — c'est l'avoir qui s'impute sur la facture, jamais l'inverse |

Ce que la copie reprend : le tiers, l'objet, la ville d'établissement, la
devise, les notes et conditions, **et les lignes avec leurs montants déjà
calculés**. Les taux de TVA ne sont pas recalculés : ce sont des instantanés
légaux (§3), et les recalculer appliquerait le barème d'aujourd'hui à un
document d'hier. Les dates, elles, repartent de zéro — l'échéance de paiement se
compte depuis la facturation, pas depuis la proposition.

Le document produit est **toujours un brouillon sans numéro** : le transfert
propose, il n'émet pas. Le numéro vient de la séquence du type CIBLE, à
l'émission.

### Côté interface

L'action figure **à deux endroits** — le menu « … » de la liste et le panneau de
détail — et suit dans les deux cas les mêmes prédicats (`isConvertible`,
`isCreditable`) : un devis doit être **émis** pour devenir une facture, une
facture doit être **émise** pour être créditée. Un brouillon n'engage encore
rien, il n'y a rien à transférer.

L'avoir passe par une confirmation, la conversion non : l'avoir défait
comptablement une pièce émise, quand la conversion ne fait qu'ajouter un
brouillon.

Le document créé n'appartient pas à la liste d'où l'action est partie. La vue
redirige donc vers **sa** liste avec `?document=<id>`, que `DocumentsView` lit
pour ouvrir le panneau de détail — c'est aussi une URL partageable vers une
pièce. Le paramètre est retiré au premier geste suivant (ouvrir une autre ligne,
ou fermer le panneau), sans quoi le panneau se rouvrirait indéfiniment sur le
même document. Les pages qui montent cette vue portent un `Suspense` : Next
l'exige pour prérendre une page qui lit les paramètres d'URL.

Un refus du serveur (devis déjà converti, facture annulée…) s'affiche en clair
au-dessus de la table, avec **son** message — l'action part d'un menu, et sans
ce retour l'utilisateur conclurait que le clic n'a rien fait.

---

## 5. Cloche des factures en retard

`features/documents/components/overdue-invoices-menu.tsx`, montée dans la
topbar à côté de la calculatrice. Badge rouge = nombre de factures au statut
`overdue`, popover = aperçu (numéro, client, reste à payer), clic = la facture
ouverte dans sa liste via `?document=<id>`.

Trois décisions :

- le décompte vient du **statut serveur**, jamais d'une échéance recomparée dans
  le navigateur : deux dates comparées côté client donneraient un chiffre
  différent de celui de la liste et des états, et le désaccord serait invisible ;
- la requête réutilise la **clé de cache** de la liste des factures filtrées sur
  `overdue` — la cloche et l'écran parlent des mêmes données, le badge ne peut
  donc pas contredire le tableau qui est juste en dessous ;
- le badge n'apparaît qu'à partir de 1 : une pastille à zéro alarme sans rien
  dire. Le compte lu est `meta.total`, pas le nombre de lignes ramenées, que la
  pagination plafonne à 25.

> ⚠️ **`overdue` est un statut POSÉ À LA MAIN**, par l'action « changer de
> statut ». Aucune tâche planifiée ne bascule les factures dont l'échéance est
> dépassée — `bootstrap/app.php` n'ordonnance rien. La cloche compte donc ce qui
> a été marqué, pas ce qui est échu. Le jour où une commande planifiée fera la
> bascule (`due_at < today` et solde > 0), la cloche la suivra sans changer
> d'une ligne : elle lit déjà le statut.
