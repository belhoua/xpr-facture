import { z } from "zod";

import { api, ensureCsrfCookie } from "@/lib/api/client";

/**
 * Référentiel des SERVICES de la société — la nature des missions menées, qui
 * classe les projets.
 *
 * ⚠️ À ne pas confondre avec `features/services`, qui couvre l'écran
 * `/services` : celui-là liste les ARTICLES DU CATALOGUE de type « service »
 * (`products`), avec prix, unité et TVA. Deux entités distinctes que le produit
 * nomme du même mot ; elles ne partagent ni table ni endpoint.
 *
 * Logé sous `features/projects` parce que le projet en est aujourd'hui le seul
 * consommateur. À déplacer dans son propre dossier le jour où un deuxième écran
 * s'en sert — pas avant, pour ne pas créer un module concurrent de
 * `features/services` sur la seule foi d'un nom.
 */
export const projectServiceSchema = z.object({
  id: z.uuid(),
  name: z.string(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const projectServiceListSchema = z.object({
  data: z.array(projectServiceSchema),
});

export type ProjectService = z.infer<typeof projectServiceSchema>;

export const projectServiceKeys = {
  all: ["project-services"] as const,
  list: () => [...projectServiceKeys.all, "list"] as const,
};

/**
 * NON PAGINÉ, comme l'endpoint : un référentiel de classement compte des
 * dizaines d'entrées, et un déroulant doit les proposer toutes ou mentir sur
 * le choix disponible.
 */
export async function fetchProjectServices(): Promise<ProjectService[]> {
  const { data } = await api.get("/services");

  return projectServiceListSchema.parse(data).data;
}

export async function createProjectService(
  name: string,
): Promise<ProjectService> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/services", { name });

  return projectServiceSchema.parse(data);
}
