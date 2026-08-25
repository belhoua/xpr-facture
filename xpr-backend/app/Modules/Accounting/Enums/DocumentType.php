<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Enums;

/**
 * Types de documents commerciaux. Valeurs miroir de la contrainte CHECK
 * `sequences_doc_type_check`, et futur discriminant de la table `documents`
 * (arbitrage du 2026-07-21 : une table unique, pas une table par type).
 *
 * Chaque type porte son format de numérotation par défaut : la séquence est
 * créée avec, et la société peut ensuite le personnaliser (§3).
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case Quote = 'quote';
    case Proforma = 'proforma';
    case PurchaseOrder = 'purchase_order';
    case DeliveryNote = 'delivery_note';
    case ShippingSlip = 'shipping_slip';
    case PurchaseInvoice = 'purchase_invoice';

    /**
     * Situation : état périodique d'une créance client (avancement de chantier,
     * décompte mensuel). Porte un montant global et un montant déjà réglé, sans
     * ventilation de TVA — ce n'est pas une pièce fiscale, c'est un suivi.
     */
    case Situation = 'situation';

    /** Format par défaut, préfixes usuels au Maroc. */
    public function defaultFormat(): string
    {
        return match ($this) {
            self::Invoice => 'FAC-{YYYY}-{0000}',
            self::Quote => 'DEV-{YYYY}-{0000}',
            self::Proforma => 'PRO-{YYYY}-{0000}',
            self::PurchaseOrder => 'BC-{YYYY}-{0000}',
            self::DeliveryNote => 'BL-{YYYY}-{0000}',
            self::ShippingSlip => 'FE-{YYYY}-{0000}',
            self::PurchaseInvoice => 'FA-{YYYY}-{0000}',
            self::Situation => 'SIT-{YYYY}-{0000}',
        };
    }

    /**
     * Types dont le montant est saisi GLOBALEMENT en en-tête, sans lignes.
     *
     * Le moteur calcule normalement le total depuis `document_items` ; pour ces
     * types, la relation s'inverse et le total devient une donnée d'entrée.
     * Une méthode plutôt qu'un `match` disséminé : la règle est lue au moins à
     * quatre endroits (validation, écriture, émission, sérialisation).
     */
    public function hasGlobalAmount(): bool
    {
        return $this === self::Situation;
    }

    /** Types portant une créance, donc un suivi de règlement. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Invoice, self::Situation], strict: true);
    }

    /**
     * Le numéro est-il attribué DÈS LA CRÉATION, sans émission explicite ?
     *
     * La règle d'origine était « non, sauf la SITUATION » (2026-08-05), qui
     * n'atteste rien auprès de la DGI et n'a donc pas besoin d'une étape
     * « émettre ».
     *
     * La FACTURE et le DEVIS l'ont rejointe le 2026-08-14, **sur décision
     * explicite de l'exploitant**, contre l'avis porté par ce dépôt. Ce que
     * cela coûte, dit ici pour que personne n'ait à le redécouvrir :
     *
     *  - une DOUBLE SOUMISSION du formulaire crée deux documents, donc consomme
     *    deux numéros. Le second ne sera jamais réattribué : la séquence porte
     *    un trou, et l'article 145 du CGI marocain exige une numérotation
     *    continue. `POST /documents` n'est protégé par AUCUNE clé
     *    d'idempotence à ce jour (la table `idempotency_keys` existe mais
     *    n'est branchée sur aucune route) — c'est le premier chantier à ouvrir
     *    si les trous deviennent visibles en exploitation ;
     *  - un appel d'essai, une erreur de saisie abandonnée, un enregistrement
     *    « pour voir » consomment eux aussi un numéro définitif. Il n'y a plus
     *    de brouillon où se tromper sans conséquence ;
     *  - la suppression reste ouverte (cf. `deletableOnceIssued()`), ce qui
     *    permet de faire disparaître la pièce mais JAMAIS de rendre son numéro.
     *
     * Ce que la décision n'enlève pas : le numéro reste tiré de `sequences`
     * dans la transaction de création, avec son verrou de ligne. Deux créations
     * concurrentes ne peuvent pas obtenir le même numéro — le risque déplacé
     * ici est celui du numéro consommé EN TROP, pas du numéro en double.
     *
     * Revenir en arrière tient en un caractère : rétablir `=== self::Situation`.
     */
    public function numbersOnCreate(): bool
    {
        return $this === self::Situation
            || $this === self::Invoice
            || $this === self::Quote;
    }

    /**
     * L'ÉTAT du document se déduit-il des montants réglés, et se saisit-il à la
     * création ?
     *
     * Distincte de `numbersOnCreate()` — et non déduite d'elle — depuis le
     * 2026-08-14, date à laquelle la facture et le devis se sont mis à
     * numéroter d'office. Les fusionner produirait deux régressions :
     *
     *  - un DEVIS « accepté » repasserait « envoyé » au moindre PATCH, parce
     *    que `refreshSettlementStatus()` réaligne l'état sur une avance qu'un
     *    devis ne porte pas. L'utilisateur perdrait un état commercial qu'il a
     *    posé sciemment, en modifiant tout autre chose ;
     *  - `status` deviendrait recevable en entrée sur une FACTURE, ce qui
     *    permettrait d'en créer une « payée » sans qu'aucun règlement n'ait eu
     *    lieu.
     *
     * Seule la SITUATION répond donc oui : son état est une donnée de suivi,
     * pas la conséquence d'un cycle commercial.
     */
    public function statusFollowsSettlement(): bool
    {
        return $this === self::Situation;
    }

    /**
     * Le CONTENU du document est-il gelé une fois numéroté ?
     *
     * Oui pour les types d'achat et d'expédition : une pièce opposable émise
     * ne se modifie pas (§3).
     *
     * Non pour la SITUATION : elle n'est pas opposable à l'administration
     * fiscale. La contrepartie est assumée et documentée dans
     * `docs/modules/situations.md` — sa séquence SIT- peut présenter des trous,
     * ce qui serait inacceptable sur `FAC-` mais sans portée ici.
     *
     * Non pour la FACTURE depuis le 2026-08-06, **sur décision explicite de
     * l'exploitant**, contre l'avis porté par ce dépôt. Ce que cela coûte, dit
     * ici pour que personne n'ait à le redécouvrir :
     *
     *  - une facture remise au client peut être modifiée après coup sans
     *    qu'aucune pièce n'atteste la correction. Depuis le retrait de l'avoir
     *    (2026-08-13, demande de l'exploitant), le produit n'offre d'ailleurs
     *    plus AUCUN instrument pour la matérialiser : seul `updated_at` en
     *    garde trace ;
     *  - `docs/modules/documents-impression.md` et le §3 du CLAUDE.md décrivent
     *    toujours la règle d'origine : ils énoncent la cible, pas l'état.
     *
     * Non pour le DEVIS depuis le 2026-08-06, à la demande de l'exploitant.
     * C'est de loin la plus défendable des trois levées : un devis n'atteste
     * rien auprès de la DGI, il PROPOSE. Le renégocier est le cours normal des
     * affaires, et l'alternative — refaire un devis à chaque ajustement de prix
     * — brûle un numéro par échange commercial. Ce qu'elle coûte quand même :
     * un devis déjà envoyé peut être modifié sans que le client le sache, et
     * deux versions d'un même `DEV-` peuvent circuler. Le numéro ne changeant
     * pas, seul l'horodatage `updated_at` en garde trace.
     *
     * Trois garde-fous SUBSISTENT et ne relèvent d'aucune de ces décisions :
     * un état TERMINAL reste clos (annulé, refusé, converti — cf.
     * `DocumentWriteService::assertEditable()`), la SUPPRESSION d'une pièce
     * numérotée obéit à `deletableOnceIssued()` et non à cette méthode, et le
     * *soft delete* laisse la ligne en base avec son `deleted_at`.
     *
     * Revenir en arrière tient en un caractère : rétablir `!== self::Situation`.
     */
    public function freezesOnIssue(): bool
    {
        return $this !== self::Situation
            && $this !== self::Invoice
            && $this !== self::Quote;
    }

    /**
     * Le NUMÉRO déjà attribué peut-il être réécrit en modification ?
     *
     * Oui pour la FACTURE et le DEVIS depuis le 2026-08-18, **à la demande
     * expresse de l'exploitant**. Distinct de `freezesOnIssue()` — et non
     * déduit de lui — parce que les deux actes ne coûtent pas la même chose :
     * corriger le contenu d'une pièce laisse son identité intacte, réécrire son
     * numéro change l'identité même sous laquelle elle a été remise au client.
     *
     * Ce que cette levée coûte, dit ici pour que personne n'ait à le
     * redécouvrir :
     *
     *  - le numéro qui figure sur l'exemplaire déjà remis au client ne
     *    correspond plus à celui de la base. Deux pièces différentes portent
     *    alors le même numéro dans la nature, ou une même pièce en porte deux ;
     *  - **le compteur de `sequences` NE SUIT PAS.** Renuméroter une facture en
     *    `FAC-2026-0042` alors que la séquence en est à 5 ne fait pas avancer
     *    celle-ci : les factures suivantes prendront 5, 6, 7… jusqu'à heurter
     *    42, où l'index unique refusera l'écriture. La collision n'arrive pas
     *    au moment de la renumérotation mais des mois plus tard, sur une facture
     *    sans rapport ;
     *  - l'article 145 du CGI marocain exige une numérotation continue, sans
     *    trou et sans réutilisation. Réécrire un numéro peut produire les deux :
     *    un trou là où il était, un doublon là où il va — l'index n'empêchant
     *    que le second ;
     *  - aucune trace de l'ancien numéro n'est conservée. Seul `updated_at` dit
     *    qu'une écriture a eu lieu, sans dire laquelle.
     *
     * NON pour tous les autres types, la SITUATION comprise : elle est déjà la
     * plus permissive du dépôt, et rien n'a été demandé pour elle. Le périmètre
     * reste le plus étroit possible — l'élargir plus tard tient en un `case`,
     * le refermer suppose de retrouver les pièces déjà renumérotées.
     *
     * Revenir en arrière tient en un mot : rendre `false` sans condition.
     */
    public function allowsNumberEdit(): bool
    {
        return $this === self::Invoice || $this === self::Quote;
    }

    /**
     * Le document peut-il être SUPPRIMÉ une fois numéroté ?
     *
     * Distinct de `freezesOnIssue()` — et non déduit de lui — parce que les
     * deux actes ne coûtent pas la même chose. Corriger une pièce laisse une
     * pièce ; la supprimer CONSOMME un numéro qui ne sera jamais réattribué et
     * troue la séquence, ce que l'article 145 du CGI marocain n'admet pas.
     *
     * Confondre les deux ferait ouvrir la suppression par simple effet de bord
     * le jour où l'on ouvre l'édition d'un type. Les deux actes ont donc bien
     * été demandés SÉPARÉMENT ici : la SITUATION (jamais opposable) et la
     * FACTURE le 2026-08-06, puis le DEVIS le 2026-08-07, sur demande expresse
     * de l'exploitant après que le coût lui a été exposé.
     *
     * Ce que la levée du 2026-08-07 coûte, dit ici pour que personne n'ait à le
     * redécouvrir : la séquence `DEV-` peut désormais présenter des TROUS. Un
     * numéro consommé puis supprimé n'est jamais réattribué, et l'article 145
     * du CGI marocain exige une numérotation continue et sans trou sur les
     * pièces qu'il vise. Le trou est irréversible : aucune fonction de ce dépôt
     * ne recompacte une séquence. La portée reste ici la plus faible possible :
     * un devis n'atteste rien auprès de la DGI, il PROPOSE — un trou dans
     * `DEV-` n'est opposable à personne. Le même raisonnement ne vaudrait pas
     * sur une pièce fiscale.
     *
     * Les trois types de cette liste sont, à ce jour, exactement ceux que
     * `freezesOnIssue()` laisse modifiables. La COÏNCIDENCE est fortuite : les
     * deux méthodes restent distinctes parce que les deux actes se décident
     * séparément, et les fusionner rendrait la prochaine levée d'édition
     * silencieusement destructrice.
     *
     * Le *soft delete* reste la seule atténuation : la ligne demeure en base
     * avec son `deleted_at`, hors de portée de l'application.
     */
    public function deletableOnceIssued(): bool
    {
        return $this === self::Situation
            || $this === self::Invoice
            || $this === self::Quote;
    }

    /**
     * La suppression reste-t-elle ouverte une fois le document CONVERTI ?
     *
     * Oui pour le DEVIS depuis le 2026-08-24, **à la demande expresse de
     * l'exploitant** après que le coût lui a été exposé. Non pour tout autre
     * type, et non pour les deux autres états terminaux du devis lui-même :
     *
     *  - `refused` n'a pas été demandé. Un devis refusé ne porte pourtant
     *    aucune descendance, il serait donc moins risqué à supprimer que
     *    celui-ci : la borne subsiste parce que personne ne l'a levée, pas
     *    parce qu'elle protège quelque chose de plus ;
     *  - `cancelled` reste fermé DÉLIBÉRÉMENT, ici comme à l'édition :
     *    l'annulation est le seul état terminal issu d'un acte volontaire, et
     *    supprimer la pièce effacerait la trace de l'annulation elle-même.
     *
     * ── Ce que cette levée coûte ──────────────────────────────────────────
     *
     * Un devis converti a produit une FACTURE, qui le désigne par
     * `parent_document_id`. Le supprimer ne coupe pas le lien — le soft delete
     * laisse la ligne en base et `Document::parent()` la résout désormais
     * `withTrashed()` — mais il change ce que ce lien vaut :
     *
     *  - la facture continue d'afficher le numéro du devis dont elle découle,
     *    y compris supprimé. C'est l'atténuation, et elle est volontaire : la
     *    traçabilité invoquée pour fermer cette porte est ce qu'il fallait
     *    préserver en l'ouvrant ;
     *  - le devis, lui, n'est plus consultable par l'application. « Sur quelle
     *    proposition cette facture repose-t-elle » reçoit un numéro, plus un
     *    document. En litige, c'est moins ;
     *  - la séquence `DEV-` prend un trou de plus, irréversible, comme toute
     *    suppression numérotée depuis le 2026-08-07. Un devis n'atteste rien
     *    auprès de la DGI — il propose — et un trou dans `DEV-` n'est
     *    opposable à personne. Le même raisonnement ne vaudrait pas sur une
     *    pièce fiscale, et c'est pourquoi la facture reste, elle, fermée ici.
     */
    public function deletableWhenConverted(): bool
    {
        return $this === self::Quote;
    }

    /**
     * Types dont une société dispose dès son inscription. Les autres (achats,
     * expédition) sont créés à la demande, quand le module correspondant est
     * livré : une séquence jamais utilisée n'a pas à exister.
     *
     * @return list<self>
     */
    public static function provisionedAtSignup(): array
    {
        return [self::Invoice, self::Quote, self::Situation];
    }
}
