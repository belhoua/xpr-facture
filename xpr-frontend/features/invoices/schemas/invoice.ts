import { z } from "zod";

import { DOCUMENT_STATUSES } from "@/components/patterns/status-badge";

/**
 * Contrat de `GET /api/v1/invoices`. Montants en centimes (§7), statut aligné
 * sur la sémantique partagée de `StatusBadge`.
 */
export const invoiceSchema = z.object({
  id: z.uuid(),
  /** Numéro attribué à la VALIDATION uniquement — nul tant que brouillon (§3). */
  number: z.string().nullable(),
  /** Tiers rattaché, nul pour un client de passage. */
  partnerId: z.uuid().nullable(),
  /** Nom FIGÉ à l'émission : il ne suit pas un renommage du tiers (§3). */
  clientName: z.string(),
  issuedAt: z.iso.date().nullable(),
  dueAt: z.iso.date().nullable(),
  status: z.enum(DOCUMENT_STATUSES),
  totalCents: z.int(),
  currency: z.string().length(3),
});

export const invoiceListSchema = z.object({
  data: z.array(invoiceSchema),
  meta: z.object({
    total: z.int().nonnegative(),
    page: z.int().positive(),
    perPage: z.int().positive(),
  }),
});

export type Invoice = z.infer<typeof invoiceSchema>;
export type InvoiceList = z.infer<typeof invoiceListSchema>;

/**
 * Statuts sélectionnables à la création/édition. `cancelled` est ABSENT à
 * dessein : l'annulation passe par une action dédiée (immuabilité §3), jamais
 * par le formulaire.
 */
export const INVOICE_FORM_STATUSES = [
  "draft",
  "sent",
  "partial",
  "paid",
  "overdue",
] as const;

export type InvoiceFormStatus = (typeof INVOICE_FORM_STATUSES)[number];

/** Un brouillon reste éditable ; toute facture validée est gelée (§3). */
export function isInvoiceEditable(invoice: Invoice): boolean {
  return invoice.status === "draft";
}

/** L'annulation ne concerne que les factures validées et non déjà annulées. */
export function isInvoiceCancellable(invoice: Invoice): boolean {
  return invoice.status !== "draft" && invoice.status !== "cancelled";
}

/**
 * Source de vérité de la validation du formulaire (§9). Le MONTANT est saisi
 * en unités majeures (MAD), plus naturel qu'en centimes ; la conversion vers
 * `totalCents` n'a lieu qu'au passage à l'API, jamais dans un calcul métier.
 * Les dates vides deviennent `null` côté payload.
 */
export const invoiceFormSchema = z
  .object({
    /**
     * "" = client de passage, non répertorié. Le select ne peut pas porter une
     * valeur nulle, d'où la chaîne vide plutôt qu'un null.
     */
    partnerId: z.string(),
    clientName: z.string().trim().max(255),
    issuedAt: z.string(),
    dueAt: z.string(),
    status: z.enum(INVOICE_FORM_STATUSES),
    amount: z
      .number("validation.amount")
      .nonnegative("validation.amount")
      .max(99999999, "validation.amount"),
    currency: z.string().length(3),
  })
  .refine(
    (values) =>
      values.issuedAt === "" ||
      values.dueAt === "" ||
      values.dueAt >= values.issuedAt,
    { path: ["dueAt"], message: "validation.dueBeforeIssued" },
  )
  // Le nom n'est exigé QUE sans tiers : avec un tiers, le serveur recopie sa
  // raison sociale et le champ est masqué. Miroir de `required_without` côté
  // FormRequest.
  .refine(
    (values) => values.partnerId !== "" || values.clientName.trim().length >= 2,
    { path: ["clientName"], message: "validation.clientName" },
  );

export type InvoiceFormValues = z.infer<typeof invoiceFormSchema>;
