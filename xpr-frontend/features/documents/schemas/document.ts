import { z } from "zod";

import { DOCUMENT_STATUSES } from "@/components/patterns/status-badge";
import { paymentSchema } from "@/features/payments/schemas/payment";

/** Miroir de `Accounting\Enums\DocumentType`. */
export const DOCUMENT_TYPES = [
  "invoice",
  "quote",
  "proforma",
  "purchase_order",
  "delivery_note",
  "shipping_slip",
  "purchase_invoice",
  "situation",
] as const;

export type DocumentType = (typeof DOCUMENT_TYPES)[number];

/**
 * Une ligne de document. Miroir de `DocumentItemResource`.
 *
 * `quantity`, `discountPercent` et `taxRate` arrivent en CHAÎNES : ce sont des
 * décimaux exacts (casts `decimal:N` côté Laravel). Les typer `number` ici
 * réintroduirait dans le contrat l'imprécision binaire que toute la chaîne de
 * calcul évite (§7).
 */
export const documentItemSchema = z.object({
  id: z.uuid(),
  productId: z.uuid().nullable(),
  position: z.int(),
  label: z.string(),
  description: z.string().nullable(),
  quantity: z.string(),
  unit: z.string().nullable(),
  unitPriceCents: z.int(),
  discountPercent: z.string(),
  taxRateId: z.uuid().nullable(),
  /** Taux FIGÉ à la saisie, pas celui du référentiel aujourd'hui (§3). */
  taxRate: z.string(),
  subtotalCents: z.int(),
  discountCents: z.int(),
  taxCents: z.int(),
  totalCents: z.int(),
});

/** Récapitulatif de TVA par taux — mention obligatoire en pied de page (§3). */
export const taxSummaryLineSchema = z.object({
  rate: z.string(),
  baseCents: z.int(),
  taxCents: z.int(),
});

/**
 * Contrat de `GET /api/v1/documents[/{id}]`. Miroir de `DocumentResource`.
 *
 * `items` et `taxSummary` sont OPTIONNELS : la liste ne charge pas les lignes
 * (ce serait une jointure pour rien), seul le détail les expose.
 */
export const documentSchema = z.object({
  id: z.uuid(),
  type: z.enum(DOCUMENT_TYPES),
  /** Attribué à l'ÉMISSION uniquement — nul tant que brouillon (§3). */
  number: z.string().nullable(),
  status: z.enum(DOCUMENT_STATUSES),
  partnerId: z.uuid().nullable(),
  /** Projet facturé. `null` sur la plupart des pièces, qui n'en relèvent d'aucun. */
  projectId: z.uuid().nullable(),
  /**
   * Titre du projet, rendu seulement quand la relation a été chargée — et
   * `null` si le projet a été archivé depuis, l'écran affichant alors « — »
   * plutôt qu'un titre inventé.
   */
  projectTitle: z.string().nullable().optional(),
  /**
   * Nom de la fiche client OUVERTE par cet enregistrement, quand un nom saisi
   * librement n'en a retrouvé aucune. Renseigné sur la seule réponse d'une
   * écriture — jamais en lecture — et `null` quand une fiche existante a été
   * retrouvée : il n'y a alors rien à annoncer.
   */
  autoCreatedPartnerName: z.string().nullable().optional(),
  parentDocumentId: z.uuid().nullable(),
  parentNumber: z.string().nullable().optional(),
  /** Identité FIGÉE à l'émission : elle ne suit pas un renommage du tiers. */
  clientName: z.string(),
  clientIce: z.string().nullable(),
  clientAddress: z.string().nullable(),
  /** Objet libre. Porte seul le sens d'une situation, qui n'a pas de lignes. */
  subject: z.string().nullable(),
  /** Ville d'établissement : « RABAT, le … » en tête du devis imprimé. */
  issueCity: z.string().nullable(),
  issuedAt: z.iso.date().nullable(),
  dueAt: z.iso.date().nullable(),
  subtotalCents: z.int(),
  discountCents: z.int(),
  taxCents: z.int(),
  totalCents: z.int(),
  /** Montant encaissé (« avance ») et solde, calculé par le serveur. */
  paidCents: z.int(),
  remainingCents: z.int(),
  currency: z.string().length(3),
  notes: z.string().nullable(),
  terms: z.string().nullable(),
  items: z.array(documentItemSchema).optional(),
  taxSummary: z.array(taxSummaryLineSchema).optional(),
  /**
   * Historique d'encaissement, du plus récent au plus ancien. Absent quand le
   * serveur n'a pas chargé la relation — d'où l'`optional()` plutôt qu'un
   * tableau vide, qui ferait passer « pas demandé » pour « aucun règlement ».
   */
  payments: z.array(paymentSchema).optional(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const documentListSchema = z.object({
  data: z.array(documentSchema),
  meta: z.object({
    total: z.int().nonnegative(),
    page: z.int().positive(),
    perPage: z.int().positive(),
  }),
});

export type Document = z.infer<typeof documentSchema>;
export type DocumentItem = z.infer<typeof documentItemSchema>;
export type DocumentList = z.infer<typeof documentListSchema>;
export type TaxSummaryLine = z.infer<typeof taxSummaryLineSchema>;

/* ------------------------------------------------------------- Formulaire */

/**
 * Une ligne en cours de saisie.
 *
 * Les grandeurs décimales (quantité, remise) sont ici des NOMBRES et non des
 * chaînes : un champ de saisie manipule un nombre, et le formulaire n'a pas
 * vocation à porter la précision comptable — le serveur recalcule tout et sa
 * réponse fait foi. L'aperçu affiché est reproduit à l'identique par
 * `features/documents/utils/totals.ts`, en entiers.
 */
export const documentItemFormSchema = z.object({
  /** "" = ligne libre, sans article du catalogue. */
  productId: z.string(),
  label: z.string().trim().min(1, "validation.required").max(255, "validation.tooLong"),
  description: z.string().trim().max(2000, "validation.tooLong"),
  quantity: z
    .number("validation.quantity")
    .gt(0, "validation.quantity")
    .max(100000, "validation.quantity"),
  unit: z.string().trim().max(20, "validation.tooLong"),
  /** Prix unitaire HT saisi en MAD ; converti en centimes à l'envoi. */
  unitPrice: z
    .number("validation.amount")
    .nonnegative("validation.amount")
    .max(99999999, "validation.amount"),
  discountPercent: z
    .number("validation.discount")
    .min(0, "validation.discount")
    .max(100, "validation.discount"),
  /** "" = pas de TVA appliquée (exonéré / hors champ). */
  taxRateId: z.string(),
});

export type DocumentItemFormValues = z.infer<typeof documentItemFormSchema>;

const baseDocumentFormSchema = z
  .object({
    /**
     * "" = client de passage, non répertorié. Un `<Select>` ne peut pas
     * porter une valeur nulle, d'où la chaîne vide plutôt qu'un null.
     */
    partnerId: z.string(),
    /**
     * "" = aucun projet. Facultatif : la plupart des pièces ne relèvent
     * d'aucun chantier, et l'exiger obligerait à en inventer un par vente.
     *
     * Ce que ce schéma NE vérifie PAS : que le projet appartienne au client
     * choisi. La règle croise deux entités et ne se tient qu'au serveur, qui
     * répond 422 sur `projectId` — le formulaire se contente de ne proposer
     * que les projets du client sélectionné.
     */
    projectId: z.string(),
    clientName: z.string().trim().max(255, "validation.tooLong"),
    /**
     * Objet du document — « Objet » du devis imprimé. Facultatif ici : seule
     * une situation l'exige, et elle a son propre formulaire.
     */
    subject: z.string().trim().max(255, "validation.tooLong"),
    /** Ville d'établissement du document. Vide ⇒ repli d'affichage (BRAND). */
    issueCity: z.string().trim().max(100, "validation.tooLong"),
    /**
     * Numéro de la pièce. La contrainte de format est posée par
     * `documentFormSchema(mode)` — elle n'est pas la même à la création et en
     * modification.
     *
     * Le champ reste une CHAÎNE et non un `number` : « 007 » est un numéro que
     * l'utilisateur a écrit, et le typer en nombre lui ôterait ses zéros
     * initiaux avant même l'envoi.
     *
     * L'unicité, elle, n'est pas vérifiable ici — elle dépend de ce que la base
     * contient. C'est le serveur qui répond, en 422 puis en 409 sur une
     * collision concurrente.
     */
    number: z.string().trim().max(30, "validation.tooLong"),
    issuedAt: z.string(),
    dueAt: z.string(),
    notes: z.string().trim().max(5000, "validation.tooLong"),
    terms: z.string().trim().max(5000, "validation.tooLong"),
    items: z.array(documentItemFormSchema).max(200),
  });

export type DocumentFormValues = z.infer<typeof baseDocumentFormSchema>;

/**
 * Schéma de saisie d'un document, dépendant du MODE.
 *
 * Seul le champ `number` diffère, et il diffère parce que la règle SERVEUR
 * diffère (cf. `DocumentStoreRequest` et `DocumentUpdateRequest`) :
 *
 *  - à la CRÉATION, c'est un compteur qu'on impose à une pièce qui n'a pas
 *    encore d'identité : des chiffres, et rien d'autre. Vide, la séquence
 *    reprend la main ;
 *  - en MODIFICATION, le champ porte le numéro TEL QU'IL S'AFFICHE
 *    (« FAC-2026-0003 ») et ne peut plus être vidé — une pièce numérotée ne
 *    redevient pas brouillon.
 *
 * Une fabrique plutôt que deux schémas jumeaux : tout le reste est commun, et
 * le dupliquer les ferait diverger au premier champ ajouté. Les deux contrôles
 * croisés sont appliqués APRÈS l'extension — un `.refine()` rend un ZodEffects,
 * qui n'expose plus `.extend()`.
 */
export function documentFormSchema(mode: "create" | "edit") {
  return baseDocumentFormSchema
    .extend({
      number:
        mode === "create"
          ? z
              .string()
              .trim()
              .regex(/^\d*$/u, "validation.numberDigits")
              .max(20, "validation.tooLong")
          : z
              .string()
              .trim()
              .min(1, "validation.required")
              .regex(
                /^[A-Za-z0-9][A-Za-z0-9/\-_.]{0,29}$/u,
                "validation.numberFormat",
              ),
    })
    .refine(
      (values) =>
        values.issuedAt === "" ||
        values.dueAt === "" ||
        values.dueAt >= values.issuedAt,
      { path: ["dueAt"], message: "validation.dueBeforeIssued" },
    )
    // Miroir de `required_without:partnerId` côté FormRequest : avec un tiers,
    // le serveur recopie sa raison sociale et le champ est masqué.
    .refine(
      (values) => values.partnerId !== "" || values.clientName.trim().length >= 2,
      { path: ["clientName"], message: "validation.clientName" },
    );
}

/* ------------------------------------------------------- Règles d'affichage */

/**
 * Un état TERMINAL clôt le document, quel que soit son type : annulé, refusé,
 * converti. Rouvrir une pièce annulée effacerait la trace de l'annulation, et
 * rouvrir un devis converti le ferait diverger de la facture qu'il a produite.
 *
 * Miroir de `DocumentStatus::isTerminal()`.
 */
const TERMINAL_STATUSES: readonly string[] = ["cancelled", "refused", "converted"];

/**
 * Types dont le CONTENU reste modifiable une fois numérotés. Miroir de
 * `DocumentType::freezesOnIssue()` :
 *  - la SITUATION, pièce de suivi et non pièce fiscale (2026-08-05) ;
 *  - la FACTURE et le DEVIS, sur décision explicite de l'exploitant
 *    (2026-08-06).
 *
 * Plus aucune pièce commerciale de vente n'est donc figée après émission : seul
 * l'état TERMINAL ferme encore un document. Ce que chaque levée coûte est
 * documenté côté backend, dans l'enum ; en retirer une ici sans l'y révoquer ne
 * ferait que masquer l'écriture, pas l'empêcher.
 */
const EDITABLE_ONCE_ISSUED: readonly string[] = ["situation", "invoice", "quote"];

/**
 * Types qu'on peut encore SUPPRIMER une fois numérotés. Miroir de
 * `DocumentType::deletableOnceIssued()` : la situation et la facture
 * (2026-08-06), puis le devis (2026-08-07).
 *
 * La liste reste DISTINCTE de `EDITABLE_ONCE_ISSUED` bien qu'elle porte
 * aujourd'hui les mêmes valeurs : les deux actes se décident séparément côté
 * serveur, et les fusionner ici rendrait la prochaine ouverture d'édition
 * silencieusement destructrice. Supprimer une pièce numérotée TROUE la
 * séquence — le numéro n'est jamais réattribué, et rien ne recompacte.
 */
const DELETABLE_ONCE_ISSUED: readonly string[] = [
  "situation",
  "invoice",
  "quote",
];

/**
 * Un brouillon est modifiable ; un document émis est gelé (§3), sauf exception.
 *
 * Miroir de `DocumentWriteService::assertEditable()` et de sa règle de
 * réouverture : depuis le 2026-08-07, un DEVIS converti ou refusé se rouvre.
 * L'ANNULATION, elle, ne se rouvre pour aucun type — c'est le seul état
 * terminal issu d'un acte délibéré, et le rouvrir effacerait la trace de
 * l'annulation elle-même.
 */
export function isEditable(document: Document): boolean {
  if (document.status === "cancelled") {
    return false;
  }

  if (TERMINAL_STATUSES.includes(document.status) && document.type !== "quote") {
    return false;
  }

  return (
    document.status === "draft" || EDITABLE_ONCE_ISSUED.includes(document.type)
  );
}

/**
 * Un brouillon se jette ; une pièce numérotée troue la séquence.
 *
 * L'état TERMINAL ferme la suppression pour TOUS les types, y compris le devis
 * que `isEditable` rouvre désormais : supprimer un devis converti couperait le
 * lien de parenté et sa facture perdrait la trace de ce dont elle découle.
 * Miroir de `DocumentWriteService::assertDeletable()`.
 */
export function isDeletable(document: Document): boolean {
  if (TERMINAL_STATUSES.includes(document.status)) {
    return false;
  }

  return (
    document.status === "draft" || DELETABLE_ONCE_ISSUED.includes(document.type)
  );
}

/**
 * L'action « Émettre » ne s'affiche que sur un BROUILLON.
 *
 * Depuis le 2026-08-14, la facture et le devis naissent numérotés : le bouton
 * disparaît donc de lui-même sur tout ce que le produit crée désormais, sans
 * qu'aucun écran n'ait à connaître la règle.
 *
 * La fonction n'est PAS supprimée pour autant, et c'est délibéré : les bases
 * antérieures à la bascule contiennent des brouillons, et les types d'achat et
 * d'expédition gardent leur étape d'émission. La retirer laisserait ces
 * documents sans aucun chemin vers leur numéro — un cul-de-sac dans l'écran,
 * pour supprimer un bouton que plus personne ne voit.
 */
export function isIssuable(document: Document): boolean {
  return document.status === "draft";
}

/**
 * L'annulation ne concerne qu'un document ÉMIS. Un brouillon se supprime, un
 * document déjà terminal (annulé, refusé, converti) ne bouge plus.
 */
export function isCancellable(document: Document): boolean {
  return (
    document.status !== "draft" &&
    document.status !== "cancelled" &&
    document.status !== "refused" &&
    document.status !== "converted"
  );
}

/**
 * Chemin de la vue d'impression d'un document, ou `null` si son type n'en a
 * pas encore.
 *
 * Chaque type a son GABARIT : un devis et une facture ne portent ni les mêmes
 * libellés, ni les mêmes mentions obligatoires. Ouvrir l'un dans le modèle de
 * l'autre imprimerait un document faux, et les vues s'en gardent explicitement.
 *
 * Cette table est ici plutôt que dans chaque menu : la liste et le panneau de
 * détail proposent tous deux l'action, et deux conditions de type recopiées
 * divergent au premier type ajouté.
 */
export function printRoute(document: Document): string | null {
  switch (document.type) {
    case "quote":
      return `/quotes/${document.id}/print`;
    case "invoice":
      return `/invoices/${document.id}/print`;
    case "situation":
      return `/situations/${document.id}/print`;
    default:
      return null;
  }
}

/** Un devis se transforme en facture une fois émis, et une seule fois. */
export function isConvertible(document: Document): boolean {
  return (
    document.type === "quote" &&
    (document.status === "sent" || document.status === "accepted")
  );
}

/**
 * Écran de LISTE d'un type de document, ou `null` si ce type n'a pas le sien.
 *
 * Sert au transfert : convertir un devis produit une facture, qui n'est pas
 * dans la liste où l'action a été déclenchée. Sans cette table, le document
 * créé n'existerait que dans une réponse HTTP — l'utilisateur devrait deviner
 * où le retrouver.
 *
 * Même raison d'être que `printRoute` : la correspondance type → route vit à un
 * seul endroit, sinon chaque menu se met à porter sa propre condition de type.
 */
export function listRoute(type: DocumentType): string | null {
  switch (type) {
    case "quote":
      return "/quotes";
    case "invoice":
      return "/invoices";
    case "situation":
      return "/situations";
    default:
      return null;
  }
}

/**
 * Un devis ou une facture peut fonder un CONTRAT DE CONVENTION.
 *
 * Miroir de `ConventionDraftingService::assertTransferable()`. Deux différences
 * assumées avec `isConvertible` :
 *  - le BROUILLON passe — la convention précède fréquemment l'émission du
 *    devis, et rien dans le contrat ne dépend d'un numéro fiscal ;
 *  - un devis CONVERTI passe aussi — il a produit une facture, c'est le signe
 *    que l'affaire est conclue, donc le moment de rédiger la convention.
 *
 * Seuls l'annulation et le refus ferment la porte : les montants n'engagent
 * plus rien, un contrat fondé dessus réclamerait un accord qui n'existe pas.
 */
export function isTransferableToConvention(document: Document): boolean {
  return (
    (document.type === "quote" || document.type === "invoice") &&
    document.status !== "cancelled" &&
    document.status !== "refused"
  );
}

/**
 * États proposés par l'action « changer de statut ». Miroir de
 * `DocumentStatus::manuallyAssignableFor()` : `draft` en est exclu (revenir au
 * brouillon casserait l'immuabilité), `cancelled` et `converted` ont leur
 * endpoint propre.
 *
 * Le serveur reste juge de la transition et répond 409 s'il la refuse — cette
 * liste ne fait que borner ce que l'interface propose.
 */
export function assignableStatuses(type: DocumentType): readonly string[] {
  switch (type) {
    case "quote":
      return ["sent", "accepted", "refused"];
    case "invoice":
    case "purchase_invoice":
      return ["sent", "partial", "paid", "overdue"];
    default:
      return ["sent", "accepted"];
  }
}
