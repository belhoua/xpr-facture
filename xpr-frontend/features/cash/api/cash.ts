import {
  cashMovementSchema,
  cashSummarySchema,
  type CashMovement,
  type CashMovementFormValues,
  type CashSummary,
} from "@/features/cash/schemas/cash";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export const cashKeys = {
  all: ["cash"] as const,
  summary: (period: string) => [...cashKeys.all, "summary", period] as const,
};

export async function fetchCashSummary(period: string): Promise<CashSummary> {
  const { data } = await api.get("/cash/movements", { params: { period } });

  return cashSummarySchema.parse(data);
}

/**
 * Contrat d'écriture (camelCase, montant EN CENTIMES signés). `toPayload` est
 * l'unique endroit où le couple (sens, montant positif) redevient un centime
 * signé — négatif pour un décaissement (§7).
 */
export interface CashMovementPayload {
  occurredAt: string;
  label: string;
  method: CashMovement["method"];
  registerName: string;
  amountCents: number;
  currency: string;
}

export function toCashPayload(
  values: CashMovementFormValues,
): CashMovementPayload {
  const cents = Math.round(values.amount * 100);

  return {
    occurredAt: values.occurredAt,
    label: values.label.trim(),
    method: values.method,
    registerName: values.registerName.trim(),
    amountCents: values.direction === "outflow" ? -cents : cents,
    currency: values.currency,
  };
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
