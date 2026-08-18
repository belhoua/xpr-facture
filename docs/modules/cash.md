# Journal de caisse — les deux sources

> Note d'architecture. Elle ne couvre pas tout le module `Cash` (§9.10 reste dû)
> mais la seule décision qui ne se lit pas dans le code : pourquoi le journal
> agrège deux tables au lieu d'une.

## Le problème

`GET /api/v1/cash/movements` ne lisait que `cash_movements`, la table des
écritures saisies à la main. Les règlements reçus sur les factures vivent dans
`payments`, alimentée depuis l'écran d'une facture. Résultat observé : un
règlement de 7 000,00 MAD encaissé sur `FAC-2026-0001` laissait l'écran Caisses
à `0,00 MAD`.

L'endpoint ne mentait pas sur sa table — il mentait sur la trésorerie, qui est
la question que l'écran pose.

## La décision : fusionner EN LECTURE

`CashSummaryService::summarize()` lit les deux tables et les fusionne au moment
de répondre. L'alternative — créer un `cash_movements` miroir à chaque règlement
enregistré, dans `PaymentWriteService` — a été écartée.

| | Fusion en lecture (retenu) | Miroir à l'écriture |
|---|---|---|
| Sources de vérité | une seule, `payments` | deux copies du même fait |
| Retrait d'un règlement | disparaît de la caisse sans rien à défaire | exige de retrouver et supprimer le miroir |
| Correction d'un montant | suit | diverge dès qu'un chemin d'écriture l'oublie |
| Règlements déjà en base | visibles immédiatement | nécessitent une reprise de données |
| Suppression du miroir depuis `/cash` | impossible, il n'existe pas | laisse une facture qui contredit la caisse |
| Coût | une requête de plus par lecture | une écriture de plus, et sa dette |

Deux copies d'un même fait divergent toujours ; la seule question est quand.
Ici, la caisse **lit** les règlements : elle ne peut pas les contredire.

## Ce que ça implique

**Le champ `source`.** Chaque ligne du journal porte `"cash"` (écriture saisie)
ou `"payment"` (règlement de facture). Il n'existe pas pour décorer : un
règlement ne se corrige que depuis sa facture, dont il dérive `paid_cents` et le
statut. L'écran retire donc « modifier » et « supprimer » sur ces lignes —
`PATCH`/`DELETE /cash/movements/{id}` répondraient d'ailleurs 404, l'identifiant
n'étant pas celui d'un mouvement (verrouillé par `CashPaymentsTest`).

**Deux champs nuls sur un règlement.** `registerName` : un virement n'entre dans
aucune caisse physique, et inventer « Caisse principale » ferait entrer 7 000 MAD
dans un tiroir qu'aucun rapprochement ne retrouverait. `invoiceId` /
`invoiceNumber` sont à l'inverse nuls sur une écriture saisie, qui ne découle
d'aucune pièce.

**Le client vient de la facture.** `documents.client_name`, figé à l'émission
(§3) — le nom qui figure sur la pièce, donc celui qui doit figurer en face de
l'encaissement, y compris si le tiers a été renommé ou archivé depuis.

**Les modes divergent entre les deux tables.** `cash_movements` accepte
`effect` ; `payments` accepte `lcn` et `deposit`. Le journal sait afficher les
sept, le formulaire n'en propose que cinq — ceux que
`cash_movements_method_check` autorise. D'où deux énumérations côté client
(`cashEntryMethodSchema` pour lire, `paymentMethodSchema` pour écrire) plutôt
qu'une seule qui mentirait d'un côté ou de l'autre.

**Aucun règlement n'est un décaissement.** La contrainte
`payments_amount_positive_check` l'impose : un remboursement relève d'un avoir,
pas d'un règlement négatif. Le filtre `direction=outflow` ne rend donc jamais de
règlement.

## Le piège restant

Rien n'empêche de saisir **à la main** un mouvement de caisse pour un règlement
déjà enregistré sur une facture. Les deux lignes s'affichent alors, et le total
compte l'encaissement deux fois. C'est un doublon de saisie, pas un défaut de
l'agrégation — mais l'écran ne le détecte pas aujourd'hui. Le jour où ça se
produit en exploitation, le rapprochement se ferait sur `(partner_id, montant,
date)`, à confirmer avec l'exploitant avant de coder une heuristique qui
masquerait des écritures légitimes.

## Permissions

La route caisse exige `cash.view`. Tous les rôles qui la portent (`owner`,
`admin`, `accountant`, `sales`, `viewer`) portent aussi `payments.view` — la
fusion n'élargit donc l'accès de personne. Si un rôle devait un jour recevoir
`cash.view` **sans** `payments.view`, cette lecture deviendrait une fuite et
`CashSummaryService` devrait filtrer.
