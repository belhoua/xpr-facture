import {
  documentListSchema,
  documentSchema,
  type Document,
  type DocumentList,
} from "@/features/documents/schemas/document";
import {
  situationSummarySchema,
  type SituationFormValues,
  type SituationSummary,
} from "@/features/situations/schemas/situation";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/**
 * Accès aux situations. Elles vivent sur les endpoints `/documents`, discriminées
 * par `type=situation` — la table est unique côté serveur (arbitrage du
 * 2026-07-21), et un module d'API séparé n'aurait fait que dupliquer le
 * contrat de lecture.
 *
 * Ce fichier n'existe donc que pour ce qui est PROPRE à la situation : le
 * `type` toujours transmis, le montant global, l'avance, et l'endpoint
 * d'agrégats des indicateurs.
 */

export interface SituationFilters {
  search: string;
  /** "all" = pas de filtre serveur. */
  status: string;
  /** Vide = toutes ; renseigné sur l'écran d'un client. */
  partnerId?: string;
  /** Bornes de la date d'émission, au format ISO (YYYY-MM-DD). */
  from?: string;
  to?: string;
}

export const situationKeys = {
  all: ["situations"] as const,
  list: (filters: SituationFilters) =>
    [...situationKeys.all, "list", filters] as const,
  summary: (filters: SituationFilters) =>
    [...situationKeys.all, "summary", filters] as const,
  detail: (id: string) => [...situationKeys.all, "detail", id] as const,
};

/** Paramètres communs à la liste et aux totaux : les deux doivent concorder. */
function toParams(filters: SituationFilters): Record<string, string | undefined> {
  return {
    type: "situation",
    search: filters.search.trim() || undefined,
    // "all" est un état de l'interface, pas un filtre serveur : l'envoyer
    // ferait rejeter une valeur d'enum inconnue.
    status: filters.status === "all" ? undefined : filters.status,
    partnerId: filters.partnerId || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
  };
}

export async function fetchSituations(
  filters: SituationFilters,
): Promise<DocumentList> {
  const { data } = await api.get("/documents", { params: toParams(filters) });

  return documentListSchema.parse(data);
}

/**
 * Totaux des quatre indicateurs. Endpoint dédié, et non une somme des lignes
 * reçues : la liste est paginée, additionner la page affichée donnerait un
 * total faux dès la 26ᵉ situation — et faux sans le dire.
 */
export async function fetchSituationSummary(
  filters: SituationFilters,
): Promise<SituationSummary> {
  const { data } = await api.get("/documents/summary", {
    params: toParams(filters),
  });

  return situationSummarySchema.parse(data);
}

export async function fetchSituation(id: string): Promise<Document> {
  const { data } = await api.get(`/documents/${id}`);

  return documentSchema.parse(data);
}

/**
 * Charge utile d'écriture.
 *
 * Le `status` y figure : c'est le seul document dont l'état est SAISI. Le
 * serveur saurait le déduire de l'avance, mais « en cours » ne se déduit
 * d'aucun montant — l'omettre priverait l'utilisateur du seul état qu'il est
 * seul à connaître. Envoyé systématiquement, y compris en PATCH : sans lui, le
 * serveur réalignerait l'état sur les montants et écraserait le choix.
 *
 * Unique frontière où les MAD saisis deviennent des centimes entiers (§7).
 * `Math.round` et non une troncature : 12,345 MAD doit donner 1235 centimes,
 * pas 1234.
 */
function toPayload(values: SituationFormValues, withType: boolean) {
  return {
    ...(withType ? { type: "situation" as const } : {}),
    partnerId: values.partnerId,
    subject: values.subject.trim(),
    issuedAt: values.issuedAt || null,
    status: values.status,
    totalCents: Math.round(values.total * 100),
    paidCents: Math.round(values.paid * 100),
    notes: values.notes.trim() || null,
  };
}

export async function createSituation(
  values: SituationFormValues,
): Promise<Document> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/documents", toPayload(values, true));

  return documentSchema.parse(data);
}

/**
 * Correction d'une situation, y compris NUMÉROTÉE — c'est la seule exception
 * au gel du §3, assumée côté serveur par `DocumentType::freezesOnIssue()`.
 * Pas de `type` : il n'est pas modifiable.
 */
export async function updateSituation(
  id: string,
  values: SituationFormValues,
): Promise<Document> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/documents/${id}`, toPayload(values, false));

  return documentSchema.parse(data);
}

/** Soft delete côté serveur : la ligne reste en base, jamais un DELETE réel. */
export async function deleteSituation(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/documents/${id}`);
}
