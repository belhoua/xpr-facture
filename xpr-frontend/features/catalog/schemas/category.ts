import { z } from "zod";

/**
 * Contrat de `GET /api/v1/categories`. Miroir de `CategoryResource`.
 *
 * `productCount` est OPTIONNEL et non nullable : le backend ne l'expose que
 * lorsque la liste a fait le `withCount` (`whenCounted`). Une valeur absente
 * n'est pas « zéro article », c'est « non compté » — les traiter pareil
 * afficherait « 0 » sur une catégorie pleine.
 */
export const categorySchema = z.object({
  id: z.uuid(),
  name: z.string(),
  description: z.string().nullable(),
  color: z.string().nullable(),
  isActive: z.boolean(),
  productCount: z.int().nonnegative().optional(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const categoryListSchema = z.object({
  data: z.array(categorySchema),
  meta: z.object({
    total: z.int().nonnegative(),
    page: z.int().positive(),
    perPage: z.int().positive(),
  }),
});

export type Category = z.infer<typeof categorySchema>;
export type CategoryList = z.infer<typeof categoryListSchema>;

/** Palette proposée à la création — les mêmes teintes que les graphiques. */
export const CATEGORY_COLORS = [
  "#3B6FE0",
  "#0E9F7E",
  "#D97706",
  "#DC2626",
  "#7C3AED",
  "#0891B2",
  "#65A30D",
  "#64748B",
] as const;

/**
 * Source de vérité de la validation du formulaire (§9). La couleur suit la
 * même expression régulière que la contrainte CHECK en base : accepter
 * « #fff » ici ne ferait que déplacer le rejet au niveau du serveur.
 */
export const categoryFormSchema = z.object({
  name: z.string().trim().min(2, "validation.minLength").max(120, "validation.tooLong"),
  description: z.string().trim().max(500, "validation.tooLong"),
  color: z
    .string()
    .regex(/^#[0-9A-Fa-f]{6}$/, "validation.color")
    .or(z.literal("")),
  isActive: z.boolean(),
});

export type CategoryFormValues = z.infer<typeof categoryFormSchema>;
