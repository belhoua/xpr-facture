import { z } from "zod";

/**
 * Contrat de `GET /api/v1/cash/movements` (Phase 2).
 *
 * `amountCents` est SIGNÉ : positif pour un encaissement, négatif pour un
 * décaissement. Un seul champ signé plutôt qu'un champ « sens » + un montant
 * absolu — la somme d'une colonne donne alors directement le flux net, sans
 * branche conditionnelle où glisser un bug.
 */
/**
 * Modes de règlement SAISISSABLES sur un mouvement de caisse — exactement ceux
 * qu'accepte `cash_movements_method_check`. Y ajouter un mode que la contrainte
 * refuse ferait échouer l'écriture en base, pas dans le formulaire.
 */
export const paymentMethodSchema = z.enum([
  "cash",
  "cheque",
  "transfer",
  "card",
  "effect",
]);

/**
 * Modes AFFICHABLES dans le journal. Sur-ensemble du précédent : les règlements
 * de factures ont deux modes de plus (`lcn`, `deposit`) que la table des
 * mouvements ne connaît pas. Les lire sans les proposer à la saisie est
 * délibéré — la caisse montre ce qui est arrivé, le formulaire n'écrit que ce
 * que la contrainte autorise.
 */
export const cashEntryMethodSchema = z.enum([
  "cash",
  "cheque",
  "transfer",
  "card",
  "effect",
  "lcn",
  "deposit",
]);

/**
 * D'où vient la ligne. `cash` = écriture saisie, corrigeable et supprimable
 * depuis cet écran ; `payment` = règlement reçu sur une facture, en LECTURE
 * SEULE ici — le corriger passe par la facture, qui en dérive `paid_cents` et
 * son statut.
 */
export const cashEntrySourceSchema = z.enum(["cash", "payment"]);

export const cashMovementSchema = z.object({
  id: z.uuid(),
  source: cashEntrySourceSchema,
  /** Tiers concerné. `null` sur un décaissement sans client (loyer, achats). */
  partnerId: z.uuid().nullable(),
  /**
   * `null` couvre DEUX cas que l'écran distingue : le mouvement sans tiers, et
   * le tiers archivé — une écriture de caisse survit à l'archivage du client.
   */
  clientName: z.string().nullable(),
  occurredAt: z.iso.date(),
  label: z.string(),
  method: cashEntryMethodSchema,
  /** `null` sur un règlement : il n'entre dans aucune caisse physique. */
  registerName: z.string().nullable(),
  amountCents: z.int(),
  currency: z.string().length(3),
  /** Renseignés sur un règlement seulement — la pièce dont il découle. */
  invoiceId: z.uuid().nullable(),
  invoiceNumber: z.string().nullable(),
});

export const cashSummarySchema = z.object({
  balanceCents: z.int(),
  inflowCents: z.int(),
  outflowCents: z.int(),
  currency: z.string().length(3),
  movements: z.array(cashMovementSchema),
});

export type PaymentMethod = z.infer<typeof paymentMethodSchema>;
export type CashEntryMethod = z.infer<typeof cashEntryMethodSchema>;
export type CashEntrySource = z.infer<typeof cashEntrySourceSchema>;
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
 * Le mode lu appartient-il au sous-ensemble SAISISSABLE ?
 *
 * Sert à repeupler le formulaire d'édition depuis une ligne du journal : celle
 * d'un règlement peut porter `lcn` ou `deposit`, que la table des mouvements
 * n'accepte pas. L'écran n'ouvre l'édition que sur une écriture saisie, mais le
 * garde rend cette invariante vérifiable plutôt que supposée.
 */
export function isEditableMethod(
  method: CashEntryMethod,
): method is PaymentMethod {
  return (PAYMENT_METHODS as readonly string[]).includes(method);
}

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
  /**
   * Tiers concerné, FACULTATIF : "" = aucun. Un décaissement n'en a souvent
   * pas — l'exiger forcerait à inventer un tiers pour chaque dépense.
   */
  partnerId: z.string(),
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
