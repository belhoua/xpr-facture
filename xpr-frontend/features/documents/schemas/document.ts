import { z } from "zod";

import { DOCUMENT_STATUSES } from "@/components/patterns/status-badge";

/** Miroir de `Accounting\Enums\DocumentType`. */
export const DOCUMENT_TYPES = [
  "invoice",
  "quote",
  "proforma",
  "purchase_order",
  "delivery_note",
  "shipping_slip",
  "credit_note",
  "purchase_invoice",
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
  parentDocumentId: z.uuid().nullable(),
  parentNumber: z.string().nullable().optional(),
  /** Identité FIGÉE à l'émission : elle ne suit pas un renommage du tiers. */
  clientName: z.string(),
  clientIce: z.string().nullable(),
  clientAddress: z.string().nullable(),
  issuedAt: z.iso.date().nullable(),
  dueAt: z.iso.date().nullable(),
  subtotalCents: z.int(),
  discountCents: z.int(),
  taxCents: z.int(),
  totalCents: z.int(),
  currency: z.string().length(3),
  notes: z.string().nullable(),
  terms: z.string().nullable(),
  items: z.array(documentItemSchema).optional(),
  taxSummary: z.array(taxSummaryLineSchema).optional(),
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

export const documentFormSchema = z
  .object({
    /**
     * "" = client de passage, non répertorié. Un `<Select>` ne peut pas
     * porter une valeur nulle, d'où la chaîne vide plutôt qu'un null.
     */
    partnerId: z.string(),
    clientName: z.string().trim().max(255, "validation.tooLong"),
    issuedAt: z.string(),
    dueAt: z.string(),
    notes: z.string().trim().max(5000, "validation.tooLong"),
    terms: z.string().trim().max(5000, "validation.tooLong"),
    items: z.array(documentItemFormSchema).max(200),
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

export type DocumentFormValues = z.infer<typeof documentFormSchema>;

/* ------------------------------------------------------- Règles d'affichage */

/** Un brouillon est modifiable ; tout document émis est gelé (§3). */
export function isEditable(document: Document): boolean {
  return document.status === "draft";
}

/** L'émission consomme un numéro : elle exige au moins une ligne. */
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

/** Un devis se transforme en facture une fois émis, et une seule fois. */
export function isConvertible(document: Document): boolean {
  return (
    document.type === "quote" &&
    (document.status === "sent" || document.status === "accepted")
  );
}

/** Corriger une facture émise = lui rattacher un avoir, jamais la modifier. */
export function isCreditable(document: Document): boolean {
  return (
    document.type === "invoice" &&
    document.status !== "draft" &&
    document.status !== "cancelled"
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
    case "credit_note":
      return ["sent", "paid"];
    default:
      return ["sent", "accepted"];
  }
}
