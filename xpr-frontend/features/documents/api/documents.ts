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
 * reste : ni `status`, ni totaux. Le statut est déduit par le serveur, et les
 * totaux sont recalculés depuis les lignes — les transmettre permettrait de
 * facturer un montant sans rapport avec le détail affiché.
 *
 * Le `number` part dans les DEUX sens depuis le 2026-08-18, mais pour des
 * raisons différentes. À la création (`type` fourni), il est facultatif : vide,
 * la clé est omise et la séquence automatique reprend la main. En PATCH, il
 * n'est émis que s'il a été saisi — la clé ABSENTE laisse le numéro intact,
 * ce qui est le cas de tous les types que `DocumentType::allowsNumberEdit()`
 * n'ouvre pas, et dont le formulaire n'affiche donc pas le champ.
 *
 * Unique frontière où les MAD saisis deviennent des centimes entiers (§7).
 */
function toPayload(values: DocumentFormValues, type?: DocumentType) {
  const partnerId = values.partnerId === "" ? null : values.partnerId;
  const number = values.number.trim();

  return {
    ...(type ? { type } : {}),
    // Jamais de clé vide : à la création elle rendrait la main à la séquence
    // — ce qu'on veut —, mais en PATCH le serveur la refuserait en 422, une
    // pièce numérotée ne pouvant pas redevenir brouillon.
    ...(number !== "" ? { number } : {}),
    partnerId,
    // Toujours émis, `null` compris : la clé ABSENTE laisse le rattachement
    // intact côté serveur, la clé à null le retire. Corriger une note ne doit
    // pas détacher le chantier, mais vider le déroulant doit le détacher.
    projectId: values.projectId === "" ? null : values.projectId,
    // Avec un tiers, l'identité vient du serveur (sa raison sociale) : on
    // n'envoie pas une saisie résiduelle du formulaire, qui prendrait le pas.
    clientName: partnerId === null ? values.clientName.trim() : null,
    subject: values.subject.trim() || null,
    issueCity: values.issueCity.trim() || null,
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

/**
 * Suppression (soft delete côté serveur).
 *
 * Portée : les brouillons de tout type, plus les FACTURES et SITUATIONS émises
 * — le gel des factures a été levé le 2026-08-06 sur décision de l'exploitant,
 * et `DocumentType::freezesOnIssue()` documente ce que cela coûte. Un devis ou
 * un avoir émis reçoit toujours un 409.
 *
 * Supprimer une pièce numérotée TROUE la séquence : le numéro est consommé et
 * ne sera pas réattribué. L'appelant doit avertir avant d'appeler.
 */
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
