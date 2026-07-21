import { z } from "zod";

/**
 * Contrat de `GET /api/v1/cash/movements` (Phase 2).
 *
 * `amountCents` est SIGNÉ : positif pour un encaissement, négatif pour un
 * décaissement. Un seul champ signé plutôt qu'un champ « sens » + un montant
 * absolu — la somme d'une colonne donne alors directement le flux net, sans
 * branche conditionnelle où glisser un bug.
 */
export const paymentMethodSchema = z.enum([
  "cash",
  "cheque",
  "transfer",
  "card",
  "effect",
]);

export const cashMovementSchema = z.object({
  id: z.uuid(),
  occurredAt: z.iso.date(),
  label: z.string(),
  method: paymentMethodSchema,
  registerName: z.string(),
  amountCents: z.int(),
  currency: z.string().length(3),
});

export const cashSummarySchema = z.object({
  balanceCents: z.int(),
  inflowCents: z.int(),
  outflowCents: z.int(),
  currency: z.string().length(3),
  movements: z.array(cashMovementSchema),
});

export type PaymentMethod = z.infer<typeof paymentMethodSchema>;
export type CashMovement = z.infer<typeof cashMovementSchema>;
export type CashSummary = z.infer<typeof cashSummarySchema>;

export const PAYMENT_METHODS = [
  "cash",
  "cheque",
  "transfer",
  "card",
  "effect",
] as const;

/**
 * Le SENS est saisi séparément du montant : l'utilisateur choisit
 * « encaissement » ou « décaissement » et tape un montant TOUJOURS positif —
 * bien plus lisible qu'un champ signé où l'on oublie le « - ». La combinaison
 * redevient le `amountCents` signé de l'API au moment de l'envoi.
 */
export const CASH_DIRECTIONS = ["inflow", "outflow"] as const;

export type CashDirection = (typeof CASH_DIRECTIONS)[number];

/**
 * Source de vérité de la validation du formulaire de caisse (§9). Montant en
 * unités majeures, converti en centimes signés à la frontière API uniquement.
 */
export const cashMovementFormSchema = z.object({
  occurredAt: z.string().min(1, "validation.required"),
  label: z.string().trim().min(2, "validation.required").max(255),
  method: paymentMethodSchema,
  registerName: z.string().trim().min(1, "validation.required").max(255),
  direction: z.enum(CASH_DIRECTIONS),
  amount: z
    .number("validation.amount")
    .positive("validation.amount")
    .max(99999999, "validation.amount"),
  currency: z.string().length(3),
});

export type CashMovementFormValues = z.infer<typeof cashMovementFormSchema>;
