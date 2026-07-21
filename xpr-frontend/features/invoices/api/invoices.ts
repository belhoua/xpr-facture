import type { DocumentStatus } from "@/components/patterns/status-badge";
import {
  invoiceSchema,
  invoiceListSchema,
  type Invoice,
  type InvoiceFormValues,
  type InvoiceList,
} from "@/features/invoices/schemas/invoice";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export interface InvoiceFilters {
  search: string;
  status: DocumentStatus | "all";
}

export const invoiceKeys = {
  all: ["invoices"] as const,
  list: (filters: InvoiceFilters) =>
    [...invoiceKeys.all, "list", filters] as const,
};

export async function fetchInvoices(
  filters: InvoiceFilters,
): Promise<InvoiceList> {
  const { data } = await api.get("/invoices", {
    params: {
      search: filters.search || undefined,
      status: filters.status === "all" ? undefined : filters.status,
    },
  });

  return invoiceListSchema.parse(data);
}

/**
 * Contrat d'écriture attendu par le backend (camelCase, montant EN CENTIMES).
 * `toPayload` est l'unique frontière où les unités majeures du formulaire
 * deviennent des centimes entiers — aucun flottant ne circule au-delà (§7).
 */
export interface InvoicePayload {
  partnerId: string | null;
  clientName: string | null;
  issuedAt: string | null;
  dueAt: string | null;
  status: Invoice["status"];
  totalCents: number;
  currency: string;
}

export function toInvoicePayload(values: InvoiceFormValues): InvoicePayload {
  const partnerId = values.partnerId === "" ? null : values.partnerId;

  return {
    partnerId,
    // Avec un tiers, le nom vient du serveur (sa raison sociale) : on n'envoie
    // pas une saisie résiduelle du formulaire, qui prendrait le pas.
    clientName: partnerId === null ? values.clientName.trim() : null,
    issuedAt: values.issuedAt === "" ? null : values.issuedAt,
    dueAt: values.dueAt === "" ? null : values.dueAt,
    status: values.status,
    totalCents: Math.round(values.amount * 100),
    currency: values.currency,
  };
}

export async function createInvoice(
  values: InvoiceFormValues,
): Promise<Invoice> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/invoices", toInvoicePayload(values));

  return invoiceSchema.parse(data);
}

export async function updateInvoice(
  id: string,
  values: InvoiceFormValues,
): Promise<Invoice> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/invoices/${id}`, toInvoicePayload(values));

  return invoiceSchema.parse(data);
}

export async function deleteInvoice(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/invoices/${id}`);
}

/**
 * Annulation d'une facture validée : seul changement d'état permis sur un
 * document immuable. Le backend refuse (409) sur un brouillon ou une facture
 * déjà annulée.
 */
export async function cancelInvoice(id: string): Promise<Invoice> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/invoices/${id}/cancel`);

  return invoiceSchema.parse(data);
}
