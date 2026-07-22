import {
  documentListSchema,
  documentSchema,
  type Document,
  type DocumentFormValues,
  type DocumentList,
  type DocumentType,
} from "@/features/documents/schemas/document";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export interface DocumentFilters {
  type: DocumentType;
  search: string;
  status: string;
}

export const documentKeys = {
  all: ["documents"] as const,
  list: (filters: DocumentFilters) =>
    [...documentKeys.all, "list", filters] as const,
  detail: (id: string) => [...documentKeys.all, "detail", id] as const,
};

export async function fetchDocuments(
  filters: DocumentFilters,
): Promise<DocumentList> {
  const { data } = await api.get("/documents", {
    params: {
      type: filters.type,
      search: filters.search || undefined,
      status: filters.status === "all" ? undefined : filters.status,
    },
  });

  return documentListSchema.parse(data);
}

/** Détail : c'est le seul endpoint qui renvoie les lignes et le récap de TVA. */
export async function fetchDocument(id: string): Promise<Document> {
  const { data } = await api.get(`/documents/${id}`);

  return documentSchema.parse(data);
}

/**
 * Charge utile d'écriture. Ce qu'elle N'ENVOIE PAS est aussi important que le
 * reste : ni `status`, ni `number`, ni totaux. Le statut naît brouillon,
 * le numéro vient de `sequences` à l'émission, et les totaux sont recalculés
 * depuis les lignes — les transmettre permettrait de facturer un montant sans
 * rapport avec le détail affiché (cf. DocumentStoreRequest).
 *
 * Unique frontière où les MAD saisis deviennent des centimes entiers (§7).
 */
function toPayload(values: DocumentFormValues, type?: DocumentType) {
  const partnerId = values.partnerId === "" ? null : values.partnerId;

  return {
    ...(type ? { type } : {}),
    partnerId,
    // Avec un tiers, l'identité vient du serveur (sa raison sociale) : on
    // n'envoie pas une saisie résiduelle du formulaire, qui prendrait le pas.
    clientName: partnerId === null ? values.clientName.trim() : null,
    issuedAt: values.issuedAt || null,
    dueAt: values.dueAt || null,
    notes: values.notes.trim() || null,
    terms: values.terms.trim() || null,
    items: values.items.map((item) => ({
      productId: item.productId || null,
      label: item.label.trim(),
      description: item.description.trim() || null,
      quantity: item.quantity,
      unit: item.unit.trim() || null,
      unitPriceCents: Math.round(item.unitPrice * 100),
      discountPercent: item.discountPercent,
      taxRateId: item.taxRateId || null,
    })),
  };
}

export async function createDocument(
  type: DocumentType,
  values: DocumentFormValues,
): Promise<Document> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/documents", toPayload(values, type));

  return documentSchema.parse(data);
}

export async function updateDocument(
  id: string,
  values: DocumentFormValues,
): Promise<Document> {
  await ensureCsrfCookie();

  // Pas de `type` : muter un devis en facture contournerait la numérotation.
  const { data } = await api.patch(`/documents/${id}`, toPayload(values));

  return documentSchema.parse(data);
}

/** Brouillons uniquement — le serveur répond 409 sur un document émis (§3). */
export async function deleteDocument(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/documents/${id}`);
}

/**
 * Émission : le document acquiert son numéro fiscal et devient immuable.
 * `issuedAt` peut être forcée — une facture saisie le 3 pour une livraison du
 * 31 est courante — mais elle désigne l'exercice, donc la séquence : une date
 * hors exercice ouvert fait répondre 409.
 */
export async function issueDocument(
  id: string,
  issuedAt?: string,
): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/documents/${id}/issue`, {
    issuedAt: issuedAt || null,
  });

  return documentSchema.parse(data);
}

/** Devis accepté/refusé, facture réglée/échue. 409 si la transition est refusée. */
export async function changeDocumentStatus(
  id: string,
  status: string,
): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/documents/${id}/status`, { status });

  return documentSchema.parse(data);
}

/** Annulation d'un document ÉMIS. Jamais un DELETE : la trace reste (§3). */
export async function cancelDocument(id: string): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/documents/${id}/cancel`);

  return documentSchema.parse(data);
}

/** Devis → facture BROUILLON : la conversion propose, elle n'émet pas. */
export async function convertDocument(id: string): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/documents/${id}/convert`);

  return documentSchema.parse(data);
}

/**
 * Facture → avoir. Seule façon légale de corriger une facture émise (§3) :
 * l'avoir naît brouillon avec les lignes d'origine, que l'utilisateur peut
 * réduire avant de l'émettre (avoir partiel).
 */
export async function createCreditNote(id: string): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/documents/${id}/credit-note`);

  return documentSchema.parse(data);
}
