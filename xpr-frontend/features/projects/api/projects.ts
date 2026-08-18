import {
  deliverableSchema,
  projectListSchema,
  projectSchema,
  type Deliverable,
  type DeliverableFormValues,
  type Project,
  type ProjectFormValues,
  type ProjectList,
} from "@/features/projects/schemas/project";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/** Accès au module Avancement de projet. */

export interface ProjectFilters {
  search: string;
  /** "all" = pas de filtre serveur. */
  status: string;
  /** "all" = tous les clients. */
  partnerId: string;
}

export const projectKeys = {
  all: ["projects"] as const,
  list: (filters: ProjectFilters) =>
    [...projectKeys.all, "list", filters] as const,
  detail: (id: string) => [...projectKeys.all, "detail", id] as const,
};

export async function fetchProjects(
  filters: ProjectFilters,
): Promise<ProjectList> {
  const { data } = await api.get("/projects", {
    params: {
      search: filters.search.trim() || undefined,
      // « all » est un état de l'interface, pas un filtre serveur : l'envoyer
      // ferait rejeter une valeur d'enum inconnue.
      status: filters.status === "all" ? undefined : filters.status,
      partnerId: filters.partnerId === "all" ? undefined : filters.partnerId,
    },
  });

  return projectListSchema.parse(data);
}

export async function fetchProject(id: string): Promise<Project> {
  const { data } = await api.get(`/projects/${id}`);

  return projectSchema.parse(data);
}

/** Charge utile d'écriture, partagée par la création et la correction. */
function toPayload(values: ProjectFormValues) {
  return {
    partnerId: values.partnerId,
    // Toujours émis, `null` compris : la clé ABSENTE laisserait le classement
    // intact côté serveur, ce qui empêcherait de déclasser un projet en
    // choisissant « Aucun ».
    serviceId: values.serviceId === "" ? null : values.serviceId,
    title: values.title.trim(),
    status: values.status,
    progressPercentage: values.progressPercentage,
    description: values.description.trim() || null,
  };
}

export async function createProject(
  values: ProjectFormValues,
): Promise<Project> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/projects", toPayload(values));

  return projectSchema.parse(data);
}

export async function updateProject(
  id: string,
  values: ProjectFormValues,
): Promise<Project> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/projects/${id}`, toPayload(values));

  return projectSchema.parse(data);
}

/**
 * Avancement seul.
 *
 * PATCH partiel : ni le titre ni le client ne sont transmis. Passer par
 * `updateProject` obligerait le tiroir de détail à porter tout le formulaire
 * pour changer deux champs, et à renvoyer des valeurs qu'il n'a pas modifiées.
 */
export async function updateProjectProgress(
  id: string,
  progress: { status: string; progressPercentage: number },
): Promise<Project> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/projects/${id}`, progress);

  return projectSchema.parse(data);
}

export async function deleteProject(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/projects/${id}`);
}

/**
 * Ajout d'un livrable. Le projet est dans le CHEMIN, jamais dans le corps : le
 * serveur le résout sous le scope tenant avant d'écrire (§5.3).
 */
export async function addDeliverable(
  projectId: string,
  values: DeliverableFormValues,
): Promise<Deliverable> {
  await ensureCsrfCookie();

  const { data } = await api.post(`/projects/${projectId}/deliverables`, {
    title: values.title.trim(),
    deliveryDate: values.deliveryDate,
    notes: values.notes.trim() || null,
  });

  return deliverableSchema.parse(data);
}

export async function deleteDeliverable(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/deliverables/${id}`);
}
