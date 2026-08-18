# Performance — état des lieux et décisions

> Passe d'optimisation du 2026-08-19. Ce document dit **ce qui a été changé,
> pourquoi, et ce qui a été délibérément laissé en l'état**. Les chiffres sont
> mesurés sur ce dépôt, pas estimés.

---

## 1. Ce que l'audit a trouvé — et pas trouvé

Le point de départ n'était pas une base à assainir. Les recherches habituelles
sont revenues vides :

| Recherche | Résultat |
|---|---|
| `console.log` / `console.debug` (front) | **aucun** |
| `dd()`, `dump()`, `var_dump()`, `ray()` (back) | **aucun** |
| Imports inutilisés (ESLint, `tsc --noEmit`) | **aucun** |
| Relations Eloquent non chargées dans une liste | **aucune** |
| PDF ou e-mail synchrone dans un contrôleur d'écriture | **aucun** |

L'eager loading était déjà systématique (`DocumentService::paginate()` charge
`project` et `payments`, `CashSummaryService` charge `partner` et `invoice`,
`ProjectService` charge ses trois relations), et chaque `Resource` protège ses
relations par `whenLoaded` — ce qui empêche un N+1 de se glisser depuis la
couche de sérialisation.

Les gains réels étaient donc ailleurs : dans le **nombre d'allers-retours SQL
par écriture**, dans le **volume hydraté par les agrégats**, dans le **poids du
lot JavaScript initial**, et dans une **rafale de requêtes déclenchée par la
frappe au clavier**.

---

## 2. Backend

### 2.1 Les lignes d'un document partent en un seul INSERT

`DocumentWriteService::replaceItems()` et `DocumentConversionService` sauvaient
les lignes une par une. Une facture de trente postes tenait trente INSERT, à
l'intérieur de la transaction qui **verrouille la ligne de séquence** — donc
avec le compteur de numérotation immobilisé pendant toute la durée.

`DocumentItem::insertMany()` les regroupe. L'insertion en masse court-circuite
les événements Eloquent : la méthode repose donc explicitement l'identifiant
(`newUniqueId()`, celui de `HasUuids`) et le `company_id`
(`TenantContext::requireId()`, celui de `BelongsToCompany`) — les deux colonnes
NOT NULL que ces événements posaient.

**Mesuré** (`DocumentQueryBudgetTest`) : le coût d'une écriture ne dépend plus
du nombre de lignes. Une facture de 30 postes coûte le même nombre de requêtes
qu'une facture de 3.

Ce gain compte doublement sur le déploiement serverless, où chaque requête SQL
traverse Internet (cf. `06-deploiement-serverless.md` §3.4) : vingt-neuf
allers-retours économisés, verrou tenu d'autant moins longtemps.

Un test de non-régression verrouille le budget. Il a été vérifié dans les deux
sens : rétabli en boucle `save()`, il échoue.

### 2.2 Le tableau de bord n'hydrate plus ce qu'il ne lit pas

Trois corrections dans `DashboardStatsService`, à résultat strictement
identique :

- la **période précédente** n'était rapatriée que pour une somme — elle passe
  en `SUM()` SQL, plus aucun modèle hydraté ;
- la **trésorerie** faisait trois parcours PHP d'une collection complète — un
  seul `SUM(...) FILTER (WHERE ...)` rend les trois cumuls ;
- les **factures de la période** sont hydratées sur les huit colonnes que les
  agrégats consultent, au lieu de la ligne entière (`notes`, `terms`, adresses
  figées comprises).

Sur un exercice complet, c'est la différence entre quelques centaines de
kilo-octets et plusieurs mégaoctets rapatriés, castés puis jetés.

### 2.3 Un index en double retiré

`documents` portait **deux index identiques** sur `(company_id, partner_id)` :
`invoices_company_id_partner_id_index`, hérité du renommage de la table, et
`documents_company_id_partner_id_index`, créé par la migration de performance
du 2026-08-16. Le `IF NOT EXISTS` de cette migration teste le **nom**, jamais
la définition : le nom était libre, l'index a été créé une seconde fois.

Un index en double n'accélère aucune lecture — le planificateur en choisit un —
mais coûte à chaque écriture et occupe deux fois la place en cache.

Migration `2026_08_19_000001_drop_duplicate_documents_index`. Les deux autres
index au nom hérité (`..._status_index`, `..._issued_at_index`) sont **gardés
et documentés** : les index `documents_*` correspondants incluent `type` en
deuxième position, et un composite ne sert que ses préfixes.

### 2.4 Mise en cache — ce qui est fait, et où elle ne s'applique pas

`config:cache`, `route:cache` et `event:cache` sont **déjà joués** au démarrage
du conteneur (`docker/entrypoint.sh`), après injection des variables
d'environnement.

Sur **Vercel**, qui est le déploiement en service, ils ne s'appliquent pas —
analyse complète en `06-deploiement-serverless.md` §3.6. En résumé : aucune
étape de build n'exécute artisan, `bootstrap/cache/` est redirigé vers `/tmp`
donc un fichier produit au build ne serait pas lu, et un `config:cache` joué au
build **figerait** les variables — panne silencieuse à la première modification
d'une valeur dans le tableau de bord.

Aucun code n'a donc été ajouté pour un cache qui ne serait jamais lu.

---

## 3. Frontend

### 3.1 Poids du lot initial

Mesuré par `next build`, First Load JS, avant → après :

| Route | Avant | Après | Gain |
|---|---:|---:|---:|
| `/dashboard` | 439 kB | 327 kB | **−112 kB (−26 %)** |
| `/invoices`, `/quotes` | 362 kB | 332 kB | −30 kB |
| `/projects` | 352 kB | 328 kB | −24 kB |
| `/partners`, `/cash` | 351 kB | 329 kB | −22 kB |
| `/services`, `/deposits` | 351 kB | 330 kB | −21 kB |
| `/catalog` | 342 kB | 321 kB | −21 kB |
| *toutes les autres routes applicatives* | | | −5 à −6 kB |

Trois leviers :

**Recharts n'est plus dans le lot du tableau de bord.** Les trois graphiques
sont ses seuls consommateurs de toute l'application, et il pesait à lui seul
plus que le reste de l'écran. Chargés par `next/dynamic` (`ssr: false` —
`ResponsiveContainer` mesure le DOM, il ne rend rien d'utile côté serveur), avec
en `loading` le squelette **déjà** affiché pendant la requête de statistiques :
la transition est la même, qu'elle attende les données ou le code. Les six
cartes d'indicateurs — ce qu'on lit en premier — s'affichent sans attendre le
moteur de graphiques.

**La palette ⌘K sort de la coquille applicative.** Elle était montée sur
**chaque** écran, cmdk et son dialogue compris, pour un panneau ouvert quelques
fois par session. `CommandPaletteHost` ne porte plus que l'écouteur clavier et
un booléen ; la palette est demandée à la première ouverture. Les deux ne
pouvaient pas rester ensemble : un raccourci embarqué dans le composant chargé
paresseusement n'existerait qu'une fois le code chargé — c'est-à-dire jamais,
si l'utilisateur compte précisément sur ⌘K pour l'ouvrir. C'est ce découplage
qui explique le gain de 5 kB sur toutes les routes.

**Les dialogues lourds sont montés à la demande.** Formulaires de document, de
tiers, de service, de mouvement de caisse, de dépôt, de projet, panneaux de
détail, fenêtre des règlements : ils sont déclarés en `dynamic` et rendus sous
`useDeferredMount`, qui retient la **première** ouverture. Le retour ne redevient
jamais faux — démonter à la fermeture supprimerait l'animation de sortie de
Radix, et ne rendrait rien au navigateur puisque le code est déjà chargé.

### 3.2 La recherche ne part plus à chaque caractère

Dix écrans de liste posaient le champ de recherche directement dans la clé
TanStack Query. Chaque frappe changeait la clé, et chaque changement de clé part
en requête : taper « MENARA » lançait **six** appels API, dont cinq dont
personne n'attendait le résultat — cinq `ILIKE` en base, cinq réponses à
sérialiser, la dernière en concurrence avec les précédentes.

`useDebouncedValue` (300 ms) ne retarde que la valeur **interrogée** ; le champ
reste piloté par l'état immédiat, la frappe ne traîne pas.

`placeholderData: keepPreviousData` a été ajouté sur les mêmes listes : la liste
précédente reste affichée pendant que la nouvelle arrive, au lieu de renvoyer le
tableau à ses squelettes à chaque pause de frappe.

C'est, en charge serveur, le gain le plus important de cette passe : une
division par cinq ou six du nombre de requêtes de recherche.

### 3.3 Feedback immédiat sur les boutons

`Button` accepte désormais `loading`. Un `disabled={mutation.isPending}` seul ne
dit rien : le bouton pâlit, mais rien ne distingue « en cours d'envoi » de
« bouton inactif ». Sur une liaison ordinaire, le clic sur « Enregistrer »
semblait sans effet — et le réflexe est de recliquer.

`loading` affiche un spinner, désactive le bouton et pose `aria-busy` pour les
lecteurs d'écran. Le spinner **prend la place** de l'icône du bouton (masquée en
CSS) plutôt que de s'y ajouter : un seul symbole pour un seul état, et pas
d'élargissement au moment du clic.

Appliqué aux 16 boutons de soumission et à la confirmation destructive du
`ConfirmDialog`. Là où plusieurs actions cohabitent (panneau de détail d'un
document, d'un projet), chaque bouton porte **sa** mutation : le spinner
n'apparaît que sur l'action cliquée, les autres restant simplement désactivées.
Sur une liste, `mutation.variables` cible la ligne concernée — sans quoi retirer
un livrable ferait tourner le spinner sur tous les autres.

### 3.4 Polices

Trois familles étaient **préchargées** sur chaque page, y compris en français :
un `<link rel="preload">` par fichier, dont cinq pour des caractères que la page
n'affichera jamais. Le préchargement est une priorité haute — il disputait la
bande passante aux ressources réellement rendues.

Seule Inter reste préchargée. `preload: false` ne retire pas les deux autres, il
retire leur empressement : le navigateur les télécharge quand une règle CSS les
réclame (RTL pour l'arabe, `<kbd>` pour la chasse fixe), avec `font-display:
swap` pour afficher le texte pendant ce temps.

### 3.5 Images

Déjà conformes : `next/image` partout (`brand-mark`, `brand-icon`,
`letterhead`), aucune balise `<img>` dans le dépôt.

---

## 4. Nettoyage

- Quatre composants shadcn jamais importés supprimés : `ui/progress.tsx`,
  `ui/table.tsx`, `ui/tabs.tsx`, `ui/tooltip.tsx` (294 lignes). Vérifiés par
  analyse des imports de tout l'arbre, `@/` et relatifs.
- `shadcn` (la CLI, 6,8 Mo) déplacée de `dependencies` vers `devDependencies` :
  elle n'est importée par aucun module, seulement invoquée en ligne de commande
  pour générer un composant. En `dependencies`, elle était installée en
  production.
- `lib/i18n/request.ts` **n'est pas** orphelin malgré l'absence d'import : il
  est désigné par chaîne dans `next.config.ts` (`createNextIntlPlugin`).

Aucune autre dépendance n'est inutilisée : `zustand`, `cmdk`, `recharts`,
`next-themes`, `sonner`, `tw-animate-css` ont chacune au moins un consommateur
vérifié.

---

## 5. Vérifications

| Contrôle | Résultat |
|---|---|
| `php artisan test` | 401 tests passent |
| `./vendor/bin/pint --test` | passé |
| `phpstan analyse` (niveau 8) | aucune erreur |
| `tsc --noEmit` | aucune erreur |
| `eslint` | aucune erreur |
| `next build` | passé |

---

## 6. Ce qui n'a pas été fait, et pourquoi

- **`DashboardStatsService` entièrement en SQL.** Les cinq méthodes restantes
  (`revenueSeries`, `statusBreakdown`, `topClients`) portent des règles métier
  fines — repli sur `client_name` quand le tiers manque, mois sans facture
  rendus à zéro. Les réécrire en agrégats SQL pour un gain marginal, une fois
  la sélection de colonnes faite, aurait mis en jeu des règles justes contre du
  temps de calcul déjà court.
- **Chargement dynamique de la calculatrice.** Elle est autonome, sans
  dépendance lourde : le découpage `Calculator` / `CalculatorPad` coûterait un
  remaniement pour quelques kilo-octets.
- **Renommage des index `invoices_*` restants.** Cosmétique, sans effet sur les
  lectures, et un renommage d'index reste une opération de schéma.
- **Cache applicatif Laravel sur Vercel.** Voir §2.4 : il ne serait pas lu, et
  la variante qui le serait figerait les variables d'environnement.
