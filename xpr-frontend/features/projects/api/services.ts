import { catalogKeys } from "@/features/catalog/api/catalog";
import {
  productListSchema,
  productSchema,
} from "@/features/catalog/schemas/product";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/**
 * Prestations proposées au classement d'un projet — les articles du CATALOGUE
 * de type « service », ceux-là mêmes que l'écran `/services` gère.
 *
 * ── Pourquoi ce fichier a changé de source le 2026-08-26 ─────────────────
 *
 * Il interrogeait `GET /services`, une table de classement distincte que seul
 * ce déroulant alimentait. Résultat : les prestations saisies dans `/services`
 * — « economie », « Béton armé » — n'y apparaissaient jamais, et le champ
 * annonçait « aucun service enregistré » à quelqu'un qui venait d'en créer.
 * Deux référentiels portant le même mot ; on en garde UN, celui que
 * l'utilisateur alimente (cf. la migration `point_project_service_to_catalog`).
 *
 * ── Les clés de cache vivent sous `catalogKeys`, et c'est le point clé ────
 *
 * Créer une prestation depuis `/services` invalide `catalogKeys.all` : le
 * déroulant se trouvant SOUS ce préfixe, il se rafraîchit sans que l'écran
 * Catalogue ait à connaître son existence. Une clé indépendante obligerait
 * chaque écran touchant au catalogue à penser à l'invalider — et le premier
 * qui l'oublierait ramènerait le bug d'origine.
 *
 * La clé n'est PAS `catalogKeys.productList(...)` : l'écran `/services`
 * l'utilise déjà avec les mêmes filtres mais une autre pagination, et deux
 * réponses différentes sous une même clé se remplaceraient l'une l'autre.
 */
export const projectServiceKeys = {
  all: catalogKeys.products(),
  list: () => [...catalogKeys.products(), "options"] as const,
};

/** Ce dont le déroulant a besoin, et rien de plus. */
export interface ProjectService {
  id: string;
  name: string;
}

/**
 * Toutes les prestations ACTIVES de la société, par ordre alphabétique (l'API
 * trie sur `name`).
 *
 * `perPage: 200` est le plafond du serveur, et il est demandé en entier plutôt
 * que page par page : un déroulant doit proposer TOUT le choix disponible ou
 * mentir sur ce qui existe. Au-delà de 200 prestations, c'est une recherche
 * côté serveur qu'il faudra brancher, pas une seconde page silencieuse.
 *
 * Les articles ARCHIVÉS sont exclus : on ne classe pas un nouveau projet sous
 * une prestation retirée. Les projets déjà classés la conservent — c'est le
 * backend qui rend `serviceName: null`, pas cette liste.
 */
export async function fetchProjectServices(): Promise<ProjectService[]> {
  const { data } = await api.get("/products", {
    params: { type: "service", active: true, perPage: 200 },
  });

  return productListSchema
    .parse(data)
    .data.map(({ id, name }) => ({ id, name }));
}

/**
 * Crée une prestation depuis le champ du projet.
 *
 * Le catalogue exige un type et un prix ; on pose « service » et ZÉRO. Le nom
 * est tout ce que la frappe fournit, et inventer un tarif rendrait l'article
 * facturable à un montant que personne n'a décidé. Il se complète ensuite
 * depuis `/services`, où l'article apparaît immédiatement — c'est le même.
 *
 * Un compte sans le droit de créer au catalogue (LECTEUR) reçoit un 403, que
 * l'appelant pose sur le champ.
 */
export async function createProjectService(
  name: string,
): Promise<ProjectService> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/products", {
    type: "service",
    name: name.trim(),
    unitPriceCents: 0,
  });

  const { id, name: created } = productSchema.parse(data);

  return { id, name: created };
}
