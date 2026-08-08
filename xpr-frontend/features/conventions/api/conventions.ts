import {
  conventionListSchema,
  conventionSchema,
  fileDepositListSchema,
  fileDepositSchema,
  type Convention,
  type ConventionFormValues,
  type ConventionList,
  type DepositFormValues,
  type FileDeposit,
  type FileDepositList,
} from "@/features/conventions/schemas/convention";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/**
 * Accès aux conventions et à leurs dépôts de dossier.
 *
 * Les deux vivent dans ce module parce qu'ils vivent dans le même module
 * serveur : un dépôt n'existe que rattaché à une convention, et l'écran de la
 * convention affiche ses dépôts. Les séparer aurait dispersé une même famille
 * de clés TanStack Query, alors que TOUTE mutation d'un dépôt doit invalider la
 * convention qui le porte (son n° de dossier en dépend).
 */

export interface ConventionFilters {
  search: string;
  /** "all" = pas de filtre serveur. */
  status: string;
}

export interface DepositFilters {
  search: string;
  status: string;
  /** Vide = tous les dépôts, toutes conventions confondues. */
  conventionId?: string;
}

export const conventionKeys = {
  all: ["conventions"] as const,
  list: (filters: ConventionFilters) =>
    [...conventionKeys.all, "list", filters] as const,
  detail: (id: string) => [...conventionKeys.all, "detail", id] as const,
};

export const depositKeys = {
  all: ["deposits"] as const,
  list: (filters: DepositFilters) => [...depositKeys.all, "list", filters] as const,
  detail: (id: string) => [...depositKeys.all, "detail", id] as const,
};

export async function fetchConventions(
  filters: ConventionFilters,
): Promise<ConventionList> {
  const { data } = await api.get("/conventions", {
    params: {
      search: filters.search.trim() || undefined,
      // "all" est un état de l'interface, pas un filtre serveur : l'envoyer
      // ferait rejeter une valeur d'enum inconnue.
      status: filters.status === "all" ? undefined : filters.status,
    },
  });

  return conventionListSchema.parse(data);
}

/** Détail : c'est le seul endpoint qui développe les dépôts et le devis source. */
export async function fetchConvention(id: string): Promise<Convention> {
  const { data } = await api.get(`/conventions/${id}`);

  return conventionSchema.parse(data);
}

/**
 * Charge utile d'écriture d'une convention.
 *
 * Deux conversions ont lieu ici, et nulle part ailleurs :
 *  - les MAD saisis deviennent des centimes entiers (§7) ;
 *  - le texte multiligne des lots devient une liste, lignes vides retirées —
 *    on colle volontiers un bloc terminé par un saut de ligne.
 */
function toConventionPayload(values: ConventionFormValues) {
  const partnerId = values.partnerId === "" ? null : values.partnerId;

  return {
    partnerId,
    ownerName: values.ownerName.trim(),
    ownerIce: values.ownerIce.trim() || null,
    ownerRc: values.ownerRc.trim() || null,
    ownerAddress: values.ownerAddress.trim() || null,

    projectDescription: values.projectDescription.trim(),
    projectAddress: values.projectAddress.trim() || null,
    projectTitleDeed: values.projectTitleDeed.trim() || null,

    dossierNumber: values.dossierNumber.trim() || null,
    status: values.status,
    issueCity: values.issueCity.trim() || null,
    issuedAt: values.issuedAt || null,

    lots: values.lots
      .split("\n")
      .map((line) => line.trim())
      .filter((line) => line !== ""),
    executionDelay: values.executionDelay.trim() || null,

    // `Math.round` et non une troncature : 12,345 MAD doit donner 1235
    // centimes, pas 1234.
    totalCents: Math.round(values.total * 100),
    advancePercent: values.advancePercent,
    visaPercent: values.visaPercent,
    completionPercent: values.completionPercent,

    notes: values.notes.trim() || null,
  };
}

export async function createConvention(
  values: ConventionFormValues,
): Promise<Convention> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/conventions", toConventionPayload(values));

  return conventionSchema.parse(data);
}

export async function updateConvention(
  id: string,
  values: ConventionFormValues,
): Promise<Convention> {
  await ensureCsrfCookie();

  const { data } = await api.patch(
    `/conventions/${id}`,
    toConventionPayload(values),
  );

  return conventionSchema.parse(data);
}

/** Soft delete côté serveur. 409 sur une convention SIGNÉE : elle s'annule. */
export async function deleteConvention(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/conventions/${id}`);
}

/**
 * Devis / facture → contrat de convention BROUILLON.
 *
 * Contrairement à la conversion devis → facture, le document source n'est PAS
 * consommé : il reste convertible, ce qui est même le cas normal — on signe la
 * convention, puis on facture l'avance.
 */
export async function createConventionFromDocument(
  documentId: string,
): Promise<Convention> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/conventions/from-document/${documentId}`);

  return conventionSchema.parse(data);
}

/* ------------------------------------------------------ Dépôts de dossier */

export async function fetchDeposits(
  filters: DepositFilters,
): Promise<FileDepositList> {
  const { data } = await api.get("/deposits", {
    params: {
      search: filters.search.trim() || undefined,
      status: filters.status === "all" ? undefined : filters.status,
      conventionId: filters.conventionId || undefined,
    },
  });

  return fileDepositListSchema.parse(data);
}

export async function fetchDeposit(id: string): Promise<FileDeposit> {
  const { data } = await api.get(`/deposits/${id}`);

  return fileDepositSchema.parse(data);
}

/**
 * `decidedAt` n'est transmise que sur un dossier TRANCHÉ. Le serveur l'effacerait
 * de toute façon sur les autres statuts — l'envoyer quand même laisserait croire
 * qu'elle a été retenue.
 */
function toDepositPayload(values: DepositFormValues) {
  const decided = values.status === "validated" || values.status === "rejected";

  return {
    reference: values.reference.trim(),
    depositedAt: values.depositedAt,
    organisation: values.organisation.trim(),
    status: values.status,
    decidedAt: decided ? values.decidedAt || null : null,
    notes: values.notes.trim() || null,
  };
}

export async function createDeposit(
  values: DepositFormValues,
): Promise<FileDeposit> {
  await ensureCsrfCookie();

  // Le rattachement passe par le CHEMIN et non par le corps : le serveur résout
  // la convention sous le scope tenant avant d'écrire, ce qu'un identifiant lu
  // dans le payload ne garantirait pas (§5.3). `toDepositPayload` ne reprend
  // donc pas `conventionId`.
  const { data } = await api.post(
    `/conventions/${values.conventionId}/deposits`,
    toDepositPayload(values),
  );

  return fileDepositSchema.parse(data);
}

export async function updateDeposit(
  id: string,
  values: DepositFormValues,
): Promise<FileDeposit> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/deposits/${id}`, toDepositPayload(values));

  return fileDepositSchema.parse(data);
}

export async function deleteDeposit(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/deposits/${id}`);
}
