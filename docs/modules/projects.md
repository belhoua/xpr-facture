# Module Avancement de projet

> Écran `/projects` (« Avancement de projet » dans la navigation). Cette note
> couvre le **périmètre** du module et les **comptes** de son bandeau
> d'indicateurs, livrés le 2026-08-24. Elle ne remplace pas une analyse
> fonctionnelle complète, que le module n'a pas encore.

---

## 1. Ce qu'un projet porte — et ce qu'il ne porte pas

Un projet est une **fiche de suivi**, pas une pièce commerciale. La table
`projects` porte : un titre, un client (`partner_id`, obligatoire), un statut, un
pourcentage d'avancement, une description, un service facultatif, et des
**livrables** (`deliverables`) datés.

Elle ne porte **ni montant, ni TVA, ni règlement, ni numéro de séquence** — rien
de ce que le §3 impose aux pièces ne s'y applique, puisqu'un projet n'est
opposable à personne.

> **Aucun montant n'est affiché sur cet écran, et c'est une décision.** Ce qu'un
> chantier a rapporté se lit sur `/situations/by-client/[clientId]` **filtré par
> projet** : là, les documents rattachés (`documents.project_id`) sont connus,
> leurs règlements aussi, et le « payé » vient de la table `payments`. Composer
> un total ici obligerait à additionner des devis et des factures pour annoncer
> un chiffre d'affaires qui n'en serait pas un.
>
> La garde est explicite dans les tests (`ProjectSummaryTest` → « n'expose AUCUN
> montant ») : la charge utile des comptes ne contient que des nombres de
> projets, et une régression le dirait.

Ce que la table **n'a pas** non plus, et qu'on croit parfois y trouver : ni date
de début ou de fin, ni responsable, ni étapes prévisionnelles. Les livrables sont
la seule notion d'étape, et ils sont **constatés** (ce qui a été remis, et quand),
pas planifiés.

---

## 1 bis. Le service d'un projet est une prestation du CATALOGUE

`projects.service_id` référence **`products`** (articles de type `service`) —
c'est-à-dire exactement ce que gère l'écran `/services`.

### Le doublon qu'on a supprimé

Ce n'était pas le cas avant le **2026-08-26**. La migration
`2026_08_18_000002_create_services_table` avait ouvert une table `services`
distincte, ne portant qu'un nom, pour classer les projets. Le déroulant du
dialogue projet était son **seul** alimentateur.

Conséquence, signalée depuis l'usage : une prestation créée dans `/services`
n'apparaissait **jamais** dans le champ du projet. Le champ annonçait « aucun
service enregistré » à quelqu'un qui venait d'en créer sur l'autre écran. Les
deux référentiels portaient le même mot et ne se rejoignaient pas.

Cette table allait déjà contre une décision écrite : `docs/modules/services.md`
§1 tranche, le **2026-08-05**, qu'il n'y a **pas de table `services`** et que
`/services` est une vue du catalogue. La correction ne fait que ramener le
dépôt à cette décision.

### Ce que la bascule tient

| Point | Règle |
|---|---|
| Clé étrangère | `projects.service_id → products.id`, `ON DELETE SET NULL` — un article effacé retire le classement, il n'emporte pas le projet |
| Validation | `ProjectStoreRequest::serviceRules()`, partagée avec le PATCH : `company_id` **et** `type = 'service'` **et** `deleted_at IS NULL` |
| Un **bien** au classement | refusé en 422 : `products` porte aussi des biens, et un chantier ne se classe pas sous une ramette de papier |
| Prestation **archivée** | les projets déjà classés la conservent (`serviceName` devient `null`) ; en poser un **nouveau** classement est refusé |
| Reprise des données | `2026_08_26_000002_point_project_service_to_catalog` copie chaque entrée de `services` dans le catalogue (prix **0**) avant de remapper — les entrées archivées comprises, sans quoi la nouvelle FK refuserait leur identifiant |

### Le rafraîchissement du déroulant

Les clés React Query du déroulant vivent **sous `catalogKeys`**
(`features/projects/api/services.ts`). Créer une prestation depuis `/services`
invalide `catalogKeys.all` et rafraîchit donc le déroulant **sans que l'écran
Catalogue ait à connaître son existence**. Une clé indépendante obligerait
chaque écran touchant au catalogue à penser à l'invalider — et le premier qui
l'oublierait ramènerait le symptôme d'origine.

### Le module `Services` backend est DORMANT

`app/Modules/Services` et sa table restent en place — la migration n'y touche
pas —, mais **plus aucun écran ne les consomme**. Leurs tests ont été sortis du
fichier des projets vers `tests/Feature/Services/ServiceReferentialTest.php`,
qui porte l'avertissement : **ne pas y rebrancher un écran**, ce serait recréer
le second référentiel et vider à nouveau le déroulant.

---

## 2. La fiche « à compléter »

Un projet est **incomplet** quand il n'a **pas de description** OU **aucun
livrable**. Deux manques, et deux seulement.

Ce sont les champs qu'une fiche ouverte à la hâte laisse systématiquement
derrière elle — un titre, un client, et plus rien.

Le **service** n'entre pas dans le critère, bien qu'il soit lui aussi
facultatif : une société peut légitimement ne pas classer ses missions, et l'y
inclure marquerait « à compléter » l'intégralité de ses projets. Un signal qui
ne redescend jamais à zéro n'en est plus un.

La règle est écrite **deux fois**, et les deux DOIVENT dire la même chose :

| Où | Quoi | Sert à |
|---|---|---|
| `ProjectService::INCOMPLETE_SQL` | `description` vide (`btrim`) ou `NOT EXISTS` sur `deliverables` | le compte de la carte |
| `isIncomplete(project)` (`schemas/project.ts`) | même critère, sur la ressource reçue | le badge et le bandeau |

Se contredire ici afficherait « 3 à compléter » au-dessus d'une liste où deux
lignes seulement portent le badge. Le `deleted_at IS NULL` de la sous-requête est
écrit à la main : c'est du SQL brut, le global scope de soft delete ne s'y
applique pas, et un livrable retiré continuerait de « compléter » la fiche.

### À l'écran

- **Liste** : un badge ambre posé sur le titre (teinte `partial` du jeu de
  statuts — aucune couleur nouvelle, §11). Pas de colonne dédiée : elle serait
  vide sur la majorité des lignes et ferait payer sa largeur à tout le tableau.
- **Tiroir de détail** : un bandeau avant les actions, avec un bouton
  **« Compléter la fiche »** qui ouvre le formulaire d'édition. Signaler un
  manque sans offrir le geste qui le comble obligerait à refermer le tiroir pour
  rouvrir le même projet autrement.

---

## 3. Les quatre comptes

`GET /api/v1/projects/summary` → `{ count, inProgress, incomplete, completed }`.

Des **nombres de projets**. Endpoint dédié plutôt qu'un décompte des lignes
reçues : la liste est paginée, compter la page afficherait « 25 projets » sur un
portefeuille qui en compte quarante — et faux sans le dire.

Il accepte **les mêmes filtres que la liste** (`search`, `status`, `partnerId`)
et les deux partagent `ProjectService::filtered()` : c'est ce qui garantit que
les cartes décrivent exactement les lignes affichées en dessous.

> La route est déclarée **avant** `projects/{project}` : Laravel retient la
> première qui matche, et le paramètre libre capterait « summary » comme un
> identifiant — 404 sur un endpoint pourtant écrit.

---

## 4. Ouverture automatique depuis un devis (2026-08-25)

Demandée par l'exploitant : un devis accepté devient un chantier à suivre, et le
rattachement fait à la main était systématiquement oublié — l'écran restait vide
alors que l'affaire tournait.

**La règle**, tenue par `DocumentWriteService::withAutoProject()` : à
l'enregistrement d'un **devis** qui ne désigne aucun projet, si l'**objet** et le
**client** sont renseignés, un projet est ouvert à son nom — ou réutilisé s'il en
existe déjà un du même intitulé chez ce client (`ProjectWriteService::openFor()`,
comparaison insensible à la casse et aux espaces de bord). Le projet naît « en
cours » à 0 %, donc **« à compléter »** au sens du §2 : il n'a qu'un nom et un
client, ce qui est exactement vrai.

### Ce que la règle coûte

**« Aucun projet » n'est plus un choix possible sur un devis.** Le formulaire
transmet toujours `projectId`, à `null` quand le déroulant est vide : rien ne
distingue « je n'en veux pas » de « je n'y ai pas pensé ». La règle tranche en
faveur du rattachement, et l'interface a été reformulée — le déroulant annonce
**« Ouvrir un chantier depuis l'objet »**, avec la mention de la réutilisation.
Une option qui n'aurait plus d'effet et continuerait de s'appeler « Aucun
projet » serait un mensonge de plus, pas une souplesse.

Détacher un devis de son chantier est donc sans effet durable : il se recrée à
l'enregistrement suivant. Le seul devis sans projet est un devis sans objet.

### Le périmètre

Le **devis seul**. Une facture née d'un devis hérite déjà du projet de celui-ci
(cf. `DocumentConversionService`) ; une facture directe, une proforma ou une
situation n'ouvrent aucun chantier. Un devis saisi au **nom libre** en ouvre un
malgré tout : la saisie libre crée d'abord une fiche tiers (2026-08-17), et c'est
elle qui porte le projet — `projects.partner_id` étant NOT NULL, il n'y a pas
d'autre issue.

### Reprise des données existantes

`php artisan xpr:backfill-quote-projects [--dry-run]` ouvre les chantiers des
devis antérieurs à la règle. Elle écrit `project_id` **directement**, sans passer
par `DocumentWriteService::update()` qui refuserait un devis converti ou annulé
(§3) : une reprise de données n'est pas une modification de pièce — ni montant,
ni numéro, ni état, seulement une colonne de rattachement introduite après coup.

La **facture issue du devis** est rattachée au même chantier : elle n'a pu
hériter d'un projet qui n'existait pas encore, et sans ce rattrapage l'écran
« situations par client » filtré sur ce chantier montrerait la proposition sans
jamais montrer ce qui a été facturé.

La commande parcourt les sociétés **une à une sous leur propre contexte tenant** :
sans lui, le global scope filtre sur une société nulle et la commande réussirait
en ne faisant rien. Idempotente — relancée, elle n'a plus rien à faire.

### Côté interface

Le formulaire de document invalide `projectKeys.all` après enregistrement dès que
la pièce porte un `projectId`. Sans cela, l'écran d'avancement servirait son cache
et resterait à zéro projet jusqu'au rechargement complet — le symptôme même que
la règle corrige. L'invalidation ne teste pas si le projet vient d'être créé : un
devis qui **rejoint** un chantier existant en change aussi le nombre de pièces
rattachées.

### Ce qui n'existe toujours pas

**L'origine n'est pas tracée.** `projects` n'a pas de colonne disant de quel
document il est né : le bandeau « à compléter » constate ce qui manque, il
n'affirme pas d'où vient la fiche. Le jour où il devra le dire, une colonne
`source_document_id` sera nécessaire — l'inférer d'une fiche vide serait faux dès
la première fiche saisie à la main et laissée en plan.

---

## 5. Tests

`tests/Feature/Projects/ProjectSummaryTest.php` — 6 cas : comptes par état et par
complétude · absence de tout montant dans la charge utile · comptes au-delà de la
première page · mêmes filtres que la liste · **isolation tenant** · le livrable
retiré fait rebasculer la fiche en « à compléter ».

Les assertions passent par un **client dédié** et le filtre `partnerId` : chaque
société de test reçoit le jeu de démonstration, qui porte déjà quatre projets.
Compter en absolu obligerait à écrire ces quatre-là en dur, et le premier ajout au
jeu de démo casserait le fichier sans qu'aucune règle n'ait bougé.
