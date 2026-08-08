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
    case CreditNote = 'credit_note';
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
            self::CreditNote => 'AV-{YYYY}-{0000}',
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
     * Règle générale : non. Le numéro est un acte fiscal engageant, il se
     * consomme par une action délibérée (§3) — sans quoi une double soumission
     * ou un appel de test brûlerait des numéros.
     *
     * La SITUATION y échappe (décision du 2026-08-05) parce qu'elle n'atteste
     * rien auprès de la DGI : c'est un état d'avancement interne, qu'on saisit
     * et corrige comme une ligne de suivi. Lui imposer une étape « émettre »
     * ajouterait un geste pour une garantie dont elle n'a pas besoin.
     */
    public function numbersOnCreate(): bool
    {
        return $this === self::Situation;
    }

    /**
     * Le CONTENU du document est-il gelé une fois numéroté ?
     *
     * Oui pour l'AVOIR et les types d'achat : une pièce opposable émise ne se
     * modifie pas, la correction passe par un avoir (§3).
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
     *    laisser d'avoir, donc sans trace comptable de la correction ;
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
     * Non pour l'AVOIR depuis le 2026-08-07, à la demande de l'exploitant.
     * C'est la plus lourde des quatre levées, et il faut la nommer pour ce
     * qu'elle est : l'avoir est l'instrument par lequel le §3 fait corriger une
     * facture émise. Le rendre modifiable à son tour ferme la boucle — plus
     * AUCUNE pièce commerciale de ce dépôt n'est désormais figée après
     * émission, et la correction d'une correction ne laisse plus de trace
     * ailleurs que dans `updated_at`. Concrètement : un avoir de 10 000 DH
     * remis au client peut devenir un avoir de 2 000 DH sans qu'aucune pièce
     * n'atteste l'écart, alors que c'est précisément l'écart qu'un contrôle
     * fiscal vient chercher.
     *
     * Ce que cela n'enlève pas : l'avoir reste RATTACHÉ à sa facture
     * (`parent_document_id`), son numéro `AV-` reste consommé, et sa séquence
     * reste continue — cf. `deletableOnceIssued()`, que cette décision ne
     * touche pas.
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
            && $this !== self::Quote
            && $this !== self::CreditNote;
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
     * FACTURE le 2026-08-06, puis le DEVIS et l'AVOIR le 2026-08-07, sur
     * demande expresse de l'exploitant après que le coût lui a été exposé.
     *
     * Ce que la levée du 2026-08-07 coûte, dit ici pour que personne n'ait à le
     * redécouvrir : les séquences `DEV-` et `AV-` peuvent désormais présenter
     * des TROUS. Un numéro consommé puis supprimé n'est jamais réattribué, et
     * l'article 145 du CGI marocain exige une numérotation continue et sans
     * trou sur les pièces qu'il vise. Le trou est irréversible : aucune
     * fonction de ce dépôt ne recompacte une séquence.
     *
     * La portée réelle diffère selon le type, et c'est ce qui rend la décision
     * défendable sur l'un et discutable sur l'autre :
     *  - le DEVIS n'atteste rien auprès de la DGI, il propose. Un trou dans
     *    `DEV-` n'est opposable à personne ;
     *  - l'AVOIR, lui, EST une pièce fiscale — c'est l'instrument qui corrige
     *    une facture. Un trou dans `AV-` se voit lors d'un contrôle, et rien
     *    dans la base ne dira ce qui occupait le numéro manquant.
     *
     * Les quatre types de cette liste sont, à ce jour, exactement ceux que
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
            || $this === self::Quote
            || $this === self::CreditNote;
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
        return [self::Invoice, self::Quote, self::CreditNote, self::Situation];
    }
}
