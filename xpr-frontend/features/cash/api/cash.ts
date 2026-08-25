import { z } from "zod";

import {
  cashMovementSchema,
  cashSummarySchema,
  type CashMovement,
  type CashMovementFormValues,
  type CashSummary,
  type PaymentMethod,
} from "@/features/cash/schemas/cash";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export const cashKeys = {
  all: ["cash"] as const,
  charges: () => [...cashKeys.all, "charges"] as const,
  summary: (period: string, direction?: string) =>
    [...cashKeys.all, "summary", period, direction ?? "all"] as const,
};

/**
 * Journal de caisse d'une période.
 *
 * `direction` ne filtre que la LISTE : les trois cumuls renvoyés portent
 * toujours sur la période entière. C'est ce qui permet à l'écran Caisses de
 * n'afficher que les encaissements tout en montrant un total encaissé juste.
 */
export async function fetchCashSummary(
  period: string,
  direction?: "inflow" | "outflow",
): Promise<CashSummary> {
  const { data } = await api.get("/cash/movements", {
    params: { period, direction },
  });

  return cashSummarySchema.parse(data);
}

/**
 * Contrat d'écriture (camelCase, montant EN CENTIMES signés). `toPayload` est
 * l'unique endroit où le couple (sens, montant positif) redevient un centime
 * signé — négatif pour un décaissement (§7).
 */
export interface CashMovementPayload {
  /** `null` = aucun tiers rattaché ; le serveur accepte l'absence. */
  partnerId: string | null;
  occurredAt: string;
  label: string;
  /** `null` = non classée. Ignorée par le serveur sur un encaissement. */
  charge: string | null;
  /**
   * Le sous-ensemble SAISISSABLE, pas celui que le journal sait afficher : la
   * table des mouvements ignore `lcn` et `deposit`, qui n'existent que sur un
   * règlement de facture.
   */
  method: PaymentMethod;
  registerName: string;
  amountCents: number;
  currency: string;
}

export function toCashPayload(
  values: CashMovementFormValues,
): CashMovementPayload {
  const cents = Math.round(values.amount * 100);

  return {
    partnerId: values.partnerId || null,
    occurredAt: values.occurredAt,
    label: values.label.trim(),
    charge: values.charge.trim() || null,
    method: values.method,
    registerName: values.registerName.trim(),
    amountCents: values.direction === "outflow" ? -cents : cents,
    currency: values.currency,
  };
}

/**
 * Natures de charge déjà employées, pour alimenter le champ de saisie.
 *
 * Le champ reste LIBRE : cette liste propose, elle n'impose pas. Elle n'est pas
 * dérivée du journal déjà chargé par l'écran, qui est borné à une période — un
 * déroulant qui oublierait « Loyer » faute de loyer ce mois-ci pousserait à le
 * ressaisir, donc à créer un doublon d'orthographe.
 */
export async function fetchCashCharges(): Promise<readonly string[]> {
  const { data } = await api.get("/cash/charges");

  return z.object({ data: z.array(z.string()) }).parse(data).data;
}

export async function createCashMovement(
  values: CashMovementFormValues,
): Promise<CashMovement> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/cash/movements", toCashPayload(values));

  return cashMovementSchema.parse(data);
}

export async function updateCashMovement(
  id: string,
  values: CashMovementFormValues,
): Promise<CashMovement> {
  await ensureCsrfCookie();

  const { data } = await api.patch(
    `/cash/movements/${id}`,
    toCashPayload(values),
  );

  return cashMovementSchema.parse(data);
}

export async function deleteCashMovement(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/cash/movements/${id}`);
}
