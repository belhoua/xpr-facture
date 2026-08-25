# Écran Services — Décisions d'architecture

> Statut : **implémenté et vérifié le 2026-08-05**. Backend (migration,
> validation, héritage sur ligne de document), frontend (`/services` FR/AR/EN),
> i18n et tests livrés. Suite Pest complète au vert : **186 tests, 621
> assertions**, dont 16 propres à cet écran. `tsc`, ESLint et `next build`
> passent.

## 1. La décision structurante : pas de table `services`

La demande initiale portait sur un **module Services complet** avec sa propre
table, son propre CRUD et son propre modèle de données.

**Décision retenue : `/services` est une VUE du catalogue, pas une entité.**

La table `products` n'a jamais été une table de produits physiques : sa migration
(`2026_07_21_000015`) la définit explicitement comme le catalogue **biens et
services**, discriminé par `type IN ('good','service')`, avec la contrainte
`products_stock_goods_only_check` qui interdit déjà de stocker un service.

Une table `services` séparée aurait imposé :

| Conséquence | Coût réel |
|---|---|
| FK polymorphe sur `document_items` | Réécriture de `DocumentItemBuilder`, `DocumentCalculator`, du rendu PDF et des tests associés |
| Duplication des attributs commerciaux | Libellé, référence, unité, prix, TVA, catégorie : 100 % communs aux deux natures |
| Second jeu de policies RLS + permissions | Deuxième surface à auditer pour le cloisonnement (§5) |
| Deux catalogues à interroger | Le sélecteur d'article des documents devrait fusionner deux listes |

Le seul attribut qui distingue réellement un bien d'un service — le suivi de
stock — tient dans un booléen déjà présent et déjà contraint en base.

> ### Cette décision a été enfreinte, puis rétablie
>
> Le **2026-08-18**, la migration `create_services_table` a ouvert une table
> `services` malgré ce qui précède — non pour facturer, mais pour **classer les
> projets**. Le résultat a été exactement le coût prévu par le tableau
> ci-dessus, dans sa version la plus visible : deux référentiels homonymes qui
> ne se rejoignaient pas. Une prestation créée dans `/services` n'apparaissait
> jamais dans le champ « service » d'un projet, qui interrogeait l'autre table.
>
> Le **2026-08-26**, `projects.service_id` a été repointé vers `products` et
> les données de `services` reprises dans le catalogue. Le module `Services` et
> sa table subsistent, **dormants** : plus aucun écran ne les consomme. Voir
> `docs/modules/projects.md` §1 bis.
>
> **Ne pas rouvrir cette porte.** Un besoin de classement se satisfait avec une
> `category` ou un type d'article, pas avec une seconde table homonyme.

**Ce qui distingue les deux écrans est donc de l'UI, pas du modèle** :
`/catalog` montre tout et laisse filtrer ; `/services` fige `type=service` et
retire de son formulaire ce qui ne concerne qu'un bien (nature, suivi de stock,
prix de revient).

## 2. Le champ « Type » (Prestation, Conseil, Maintenance, Forfait)

**Décision : ce sont des `categories`, pas une colonne `service_kind`.**

Une colonne à valeurs figées aurait exigé une migration à chaque nomenclature
métier — or celle d'un cabinet comptable n'est pas celle d'une agence web, et §3
impose que tout référentiel métier soit paramétrable en base.

`CompanyCatalogProvisioning` dote donc toute société neuve de quatre catégories
(Prestation, Conseil, Maintenance, Forfait), qu'elle peut renommer, archiver ou
compléter depuis l'écran. Le jeu de démonstration s'y raccroche via
`firstOrCreate` plutôt que de créer des homonymes que
`categories_company_name_unique` (sur `lower(name)`) refuserait.

## 3. La remise par défaut

Nouveau champ `products.default_discount_percent` — `DECIMAL(5,2) NOT NULL
DEFAULT 0`, contrainte `CHECK (>= 0 AND <= 100)`.

Quatre points qui ont dicté la forme :

1. **C'est une valeur de saisie, pas une règle de prix.** Elle est recopiée dans
   `document_items.discount_percent` puis la ligne vit sa vie. Modifier la fiche
   ne rétroagit sur aucun document déjà émis — même traitement que `tax_rate_id`
   (§3, immuabilité). Verrouillé par un test dédié.
2. **Le prix catalogue reste le prix plein.** L'amputer de la remise rendrait
   impossible de distinguer le chiffre d'affaires brut de l'effort commercial.
3. **`DECIMAL(5,2)` et non un entier de centièmes.** C'est le type de
   `document_items.discount_percent`, vers lequel la valeur est recopiée : deux
   types différents introduiraient un arrondi à la copie. La règle « montants en
   centimes entiers » (§7) vise les montants, pas les pourcentages.
4. **`NOT NULL DEFAULT 0` et non nullable.** « Pas de remise » et « remise
   nulle » désignent la même chose commercialement ; un `NULL` obligerait chaque
   appelant à le coalescer avant calcul.

### Héritage sur la ligne de document

`DocumentItemBuilder` applique la règle habituelle « payload → catalogue →
neutre », avec une nuance : la distinction se fait par `array_key_exists` et non
par `??`.

- champ **absent** → la remise de la fiche s'applique ;
- champ **transmis à 0** → 0 s'applique.

Sans cette distinction, un vendeur ne pourrait pas retirer la remise habituelle
sur une ligne donnée. L'héritage est fait **côté serveur**, pas seulement
pré-rempli par le formulaire : l'API doit se comporter correctement pour un
client qui n'est pas notre interface (§10). Le formulaire pose quand même la
valeur à la sélection de l'article, pour qu'elle soit **visible et corrigeable
avant l'envoi** plutôt que découverte dans la réponse.

## 4. Correction incidente

`ProductService::toColumns()` coalesce désormais vers le défaut de la colonne
les champs `unit` et `default_discount_percent` reçus à `null`.

Le frontend transmet `null` pour « champ laissé vide » (`toProductPayload`), or
ces deux colonnes sont `NOT NULL DEFAULT` : un article enregistré sans unité
partait en `23502`. Le repli rend aussi la **mise à jour** correcte — vider
l'unité la ramène à « unité » au lieu de conserver l'ancienne.

## 5. Périmètre livré

**Backend**
- `2026_08_05_000001_add_default_discount_to_products.php`
- `Catalog/Services/CompanyCatalogProvisioning.php` — nomenclature de départ
- `Product`, `ProductStoreRequest`, `ProductResource`, `ProductService`,
  `ProductFactory` — propagation du champ
- `Documents/Services/DocumentItemBuilder.php` — héritage de la remise
- `Tenancy/Services/CompanyProvisioning.php` — appel du provisioning catalogue
- `tests/Feature/Catalog/ServiceCatalogTest.php` — 16 tests

**Frontend**
- `app/[locale]/(app)/services/page.tsx` — FR / AR / EN
- `features/services/` — `services-view`, `service-form-dialog`, `schemas/service`
- `features/catalog/` — champ remise ajouté au schéma, à l'API et au formulaire
- `features/documents/components/document-line-editor.tsx` — report de la remise
- `components/layout/navigation.ts` — entrée sous « Gestion », raccourci ⌘S
- `messages/{fr,ar,en}.json`

**Non modifié, car déjà conforme**
Les modales Facture / Devis / Avoir chargent le catalogue avec `type: "all"` :
les services y étaient déjà sélectionnables, et `applyProduct()` y recopiait
déjà libellé, prix, unité et taux.

## 6. Endpoints

Aucun endpoint nouveau. L'écran consomme l'API catalogue existante :

| Méthode | Route | Usage par l'écran |
|---|---|---|
| `GET` | `/api/v1/products?type=service&search=&categoryId=` | Liste filtrée |
| `POST` | `/api/v1/products` | Création (`type` forcé à `service`) |
| `PATCH` | `/api/v1/products/{product}` | Édition |
| `DELETE` | `/api/v1/products/{product}` | **Archivage**, jamais suppression |
| `GET` | `/api/v1/categories` | Filtre et sélecteur « Type » |
| `GET` | `/api/v1/tax-rates` | Sélecteur de TVA |

Contrat enrichi d'un champ : `defaultDiscountPercent`, sérialisé en **chaîne**
(« 12.50 ») comme `taxRateValue` — la valeur est reportée telle quelle, jamais
recalculée côté client.

## 7. Unités de mesure

Saisie **libre avec suggestions** (`<datalist>`), pas un `<select>` fermé. La
colonne est du texte libre et aucune règle fiscale n'en dépend — contrairement au
taux de TVA, lui référencé. La liste réelle varie par métier (« nuitée », « m² »,
« intervention », « kilomètre ») : une liste fermée imposerait une migration à
chaque nouveau métier servi.

## 8. Points ouverts

- **Retenue à la source.** §3 la prévoit paramétrable et elle ne s'applique au
  Maroc qu'aux **prestations de services** — donc précisément aux lignes portant
  `type = 'service'`. Rien n'est implémenté à ce stade ; le discriminant qui
  permettra de la cibler existe déjà.
- **`make back-test` est cassé pour un run sur l'hôte.** La cible fait
  `cd xpr-backend && php artisan test`, alors que `phpunit.xml` fixe
  `DB_HOST=postgres` — un nom que seul le réseau Docker résout. Contourné ici
  par `$(COMPOSE) exec php php artisan test`. À corriger dans le `Makefile`,
  indépendamment de cet écran.
