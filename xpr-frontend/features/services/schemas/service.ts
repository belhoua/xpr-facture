import type { ProductFilters } from "@/features/catalog/api/catalog";

/**
 * Un service n'a ni schéma ni type propres : c'est un article du catalogue dont
 * `type` vaut « service ». Ce fichier ne porte donc que ce qui est spécifique à
 * l'ÉCRAN — le reste vient de `features/catalog/schemas/product`.
 *
 * Dupliquer un `serviceSchema` reviendrait à maintenir deux contrats pour une
 * seule ressource API, qui divergeraient à la première évolution.
 */

/**
 * Unités SUGGÉRÉES à la saisie, pas imposées : la colonne est du texte libre et
 * aucune règle fiscale n'en dépend (cf. migration `create_products_table`). Un
 * cabinet facture à l'heure, un hôtel à la nuitée, un transporteur au kilomètre.
 */
export const SERVICE_UNITS = [
  "heure",
  "jour",
  "demi-journée",
  "mois",
  "an",
  "forfait",
  "intervention",
] as const;

/** Filtres de l'écran Services : le type est figé, il n'est pas à la main de l'utilisateur. */
export interface ServiceFilters {
  search: string;
  categoryId: string | "all";
}

/** Traduit les filtres de l'écran vers le contrat de `GET /api/v1/products`. */
export function toProductFilters(filters: ServiceFilters): ProductFilters {
  return {
    search: filters.search,
    type: "service",
    categoryId: filters.categoryId,
  };
}
