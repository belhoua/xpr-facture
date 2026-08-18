import { api, ensureCsrfCookie } from "@/lib/api/client";
import {
  isBankInstrument,
  paymentListSchema,
  paymentSchema,
  type Payment,
  type PaymentFormValues,
} from "@/features/payments/schemas/payment";

/** Clés TanStack Query du module. */
export const paymentKeys = {
  all: ["payments"] as const,
  ofInvoice: (invoiceId: string) => ["payments", invoiceId] as const,
};

export async function fetchPayments(invoiceId: string) {
  const { data } = await api.get(`/invoices/${invoiceId}/payments`);

  return paymentListSchema.parse(data);
}

/**
 * Enregistre un règlement.
 *
 * Envoyé en `multipart/form-data` et non en JSON : le scan est un fichier, et
 * un binaire encodé en base64 dans du JSON gonfle d'un tiers pour rien. Les
 * champs vides sont OMIS plutôt qu'envoyés vides — `nullable` côté serveur
 * accepte l'absence, mais `date` refuserait une chaîne vide.
 *
 * Les champs d'EFFET ne partent que sur un chèque ou une LCN : le serveur les
 * ignorerait ailleurs, mais `scan` y est explicitement interdit et ferait
 * échouer la requête en 422.
 */
export async function createPayment(
  invoiceId: string,
  values: PaymentFormValues,
  scan: File | null,
): Promise<Payment> {
  await ensureCsrfCookie();

  const form = new FormData();

  // Unités majeures → centimes entiers : unique frontière de conversion (§7).
  form.append("amountCents", String(Math.round(values.amount * 100)));
  form.append("paidOn", values.paidOn);
  form.append("method", values.method);

  if (values.reference.trim() !== "") {
    form.append("reference", values.reference.trim());
  }

  if (values.notes.trim() !== "") {
    form.append("notes", values.notes.trim());
  }

  if (isBankInstrument(values.method)) {
    form.append("checkNumber", values.checkNumber.trim());
    form.append("checkStatus", values.checkStatus);

    if (values.bankDepositDate !== "") {
      form.append("bankDepositDate", values.bankDepositDate);
    }

    if (values.receivedDate !== "") {
      form.append("receivedDate", values.receivedDate);
    }

    if (scan !== null) {
      form.append("scan", scan);
    }
  }

  const { data } = await api.post(`/invoices/${invoiceId}/payments`, form);

  return paymentSchema.parse(data);
}

/**
 * Retire un règlement.
 *
 * *Soft delete* côté serveur, qui réaligne la facture dans la même transaction :
 * une facture « payée » dont on retire le règlement redevient « envoyée » au
 * même instant.
 */
export async function deletePayment(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/payments/${id}`);
}
