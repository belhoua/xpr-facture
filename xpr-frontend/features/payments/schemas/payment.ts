import { z } from "zod";

/**
 * Modes de règlement. Miroir de `Payments\Enums\PaymentMethod`.
 *
 * La liste est celle du terrain marocain : le chèque y reste dominant, la LCN
 * (lettre de change relevé) est l'effet de commerce courant entre entreprises,
 * et le versement désigne l'espèce déposée au guichet — distinct du virement,
 * qui part d'un compte.
 */
export const PAYMENT_METHODS = [
  "cash",
  "cheque",
  "transfer",
  "card",
  "lcn",
  "deposit",
] as const;

export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

/** Sort d'un effet remis. Miroir de `Payments\Enums\CheckStatus`. */
export const CHECK_STATUSES = ["pending", "cashed", "rejected"] as const;

export type CheckStatus = (typeof CHECK_STATUSES)[number];

/**
 * Le mode porte-t-il un titre qui circule ?
 *
 * Miroir de `PaymentMethod::isBankInstrument()`. Ces deux-là seuls ouvrent les
 * champs de numéro, de dates, de statut et de scan : entre la remise et
 * l'encaissement s'écoulent des jours, et l'effet peut revenir impayé.
 */
export function isBankInstrument(method: string): boolean {
  return method === "cheque" || method === "lcn";
}

/** Contrat de `GET|POST /api/v1/invoices/{id}/payments`. */
export const paymentSchema = z.object({
  id: z.uuid(),
  invoiceId: z.uuid(),
  amountCents: z.int(),
  currency: z.string(),
  paidOn: z.string(),
  method: z.enum(PAYMENT_METHODS),
  reference: z.string().nullable(),
  notes: z.string().nullable(),
  checkNumber: z.string().nullable(),
  bankDepositDate: z.string().nullable(),
  receivedDate: z.string().nullable(),
  checkStatus: z.enum(CHECK_STATUSES).nullable(),
  scanName: z.string().nullable(),
  /** URL d'un endpoint AUTHENTIFIÉ, jamais un chemin de disque. */
  scanUrl: z.url().nullable(),
  createdAt: z.string().nullable(),
});

export type Payment = z.infer<typeof paymentSchema>;

/**
 * Cumuls de la facture, calculés par le SERVEUR.
 *
 * « Reste à payer » n'est pas soustrait ici : c'est une opération que chaque
 * client réécrirait à sa façon, et un `max(0, …)` oublié afficherait un solde
 * négatif sur une facture trop-perçue.
 */
export const paymentSummarySchema = z.object({
  totalCents: z.int(),
  paidCents: z.int(),
  remainingCents: z.int(),
  currency: z.string(),
  status: z.string(),
});

export type PaymentSummary = z.infer<typeof paymentSummarySchema>;

export const paymentListSchema = z.object({
  data: z.array(paymentSchema),
  summary: paymentSummarySchema,
});

/**
 * Formulaire d'ajout.
 *
 * `amount` est saisi en unités majeures (MAD) et converti en centimes à
 * l'envoi : c'est la même frontière que le formulaire de document (§7). Les
 * champs d'effet sont conditionnels — exigés sur un chèque ou une LCN, ignorés
 * ailleurs — ce que `superRefine` exprime, faute de pouvoir le dire par type.
 */
export const paymentFormSchema = z
  .object({
    amount: z
      .number("validation.amount")
      .positive("validation.amount")
      .max(99999999, "validation.amount"),
    paidOn: z.string().min(1, "validation.required"),
    method: z.enum(PAYMENT_METHODS),
    reference: z.string().trim().max(255, "validation.tooLong"),
    notes: z.string().trim().max(2000, "validation.tooLong"),
    checkNumber: z.string().trim().max(50, "validation.tooLong"),
    checkStatus: z.enum(CHECK_STATUSES),
    bankDepositDate: z.string(),
    receivedDate: z.string(),
  })
  .superRefine((values, context) => {
    if (!isBankInstrument(values.method)) {
      return;
    }

    if (values.checkNumber.trim() === "") {
      context.addIssue({
        code: "custom",
        path: ["checkNumber"],
        message: "validation.required",
      });
    }

    // On ne dépose pas en banque un titre qu'on n'a pas encore reçu. Comparaison
    // seulement si les deux dates sont saisies : chacune reste facultative.
    if (
      values.receivedDate !== "" &&
      values.bankDepositDate !== "" &&
      values.receivedDate > values.bankDepositDate
    ) {
      context.addIssue({
        code: "custom",
        path: ["receivedDate"],
        message: "validation.receivedAfterDeposit",
      });
    }
  });

export type PaymentFormValues = z.infer<typeof paymentFormSchema>;
