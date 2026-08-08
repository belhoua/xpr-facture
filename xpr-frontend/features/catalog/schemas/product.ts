import { z } from "zod";

/** Miroir de `Catalog\Enums\ProductType`. */
export const PRODUCT_TYPES = ["good", "service"] as const;

export type ProductType = (typeof PRODUCT_TYPES)[number];

/**
 * Contrat de `GET /api/v1/products`. Miroir de `ProductResource`.
 *
 * `taxRateValue` arrive en CHAÎNE (« 20.00 ») et non en nombre : c'est un
 * décimal exact que le front reformate à l'affichage sans jamais s'en servir
 * pour recalculer une TVA — le serveur seul calcule les montants (§7).
 */
export const productSchema = z.object({
  id: z.uuid(),
  type: z.enum(PRODUCT_TYPES),
  reference: z.string().nullable(),
  name: z.string(),
  description: z.string().nullable(),
  unit: z.string().nullable(),
  unitPriceCents: z.int(),
  costPriceCents: z.int().nullable(),
  /**
   * Remise habituellement consentie, en pourcentage. CHAÎNE (« 10.00 ») pour la
   * même raison que `taxRateValue` : la valeur est reportée telle quelle sur la
   * ligne de document, jamais recalculée côté client.
   */
  defaultDiscountPercent: z.string(),
  /** Marge unitaire HT, calculée par le serveur ; nulle sans prix de revient. */
  marginCents: z.int().nullable(),
  currency: z.string().length(3),
  categoryId: z.uuid().nullable(),
  categoryName: z.string().nullable(),
  categoryColor: z.string().nullable(),
  taxRateId: z.uuid().nullable(),
  taxRateValue: z.string().nullable(),
  taxRateLabel: z.string().nullable(),
  trackStock: z.boolean(),
  isActive: z.boolean(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const productListSchema = z.object({
  data: z.array(productSchema),
  meta: z.object({
    total: z.int().nonnegative(),
    page: z.int().positive(),
    perPage: z.int().positive(),
  }),
});

export type Product = z.infer<typeof productSchema>;
export type ProductList = z.infer<typeof productListSchema>;

/** Contrat de `GET /api/v1/tax-rates`. Miroir de `TaxRateResource`. */
export const taxRateSchema = z.object({
  id: z.uuid(),
  labelFr: z.string(),
  labelAr: z.string().nullable(),
  rate: z.string(),
  kind: z.string(),
  isDefault: z.boolean(),
  isGlobal: z.boolean(),
});

export const taxRateListSchema = z.object({ data: z.array(taxRateSchema) });

export type TaxRate = z.infer<typeof taxRateSchema>;

/**
 * Validation du formulaire d'article (§9).
 *
 * Les PRIX sont saisis en unités majeures (MAD) — plus naturel que des
 * centimes — et convertis à la frontière de l'API, jamais dans un calcul.
 * Les sélecteurs portent "" pour « aucun » : un `<Select>` ne peut pas
 * transporter `null`.
 */
export const productFormSchema = z
  .object({
    type: z.enum(PRODUCT_TYPES),
    name: z.string().trim().min(2, "validation.minLength").max(255, "validation.tooLong"),
    reference: z.string().trim().max(40, "validation.tooLong"),
    description: z.string().trim().max(5000, "validation.tooLong"),
    unit: z.string().trim().max(20, "validation.tooLong"),
    categoryId: z.string(),
    taxRateId: z.string(),
    unitPrice: z.number("validation.amount").nonnegative("validation.amount").max(99999999, "validation.amount"),
    costPrice: z.number("validation.amount").nonnegative("validation.amount").max(99999999, "validation.amount"),
    // Un POURCENTAGE, pas un montant : bornes alignées sur la contrainte
    // `products_default_discount_check` et sur celle de la ligne de document.
    defaultDiscount: z
      .number("validation.discount")
      .min(0, "validation.discount")
      .max(100, "validation.discount"),
    trackStock: z.boolean(),
    isActive: z.boolean(),
  })
  // Miroir de la contrainte `products_stock_goods_only_check` : seul un BIEN
  // est stockable. Le backend corrige silencieusement, mais laisser la case
  // cochée sur un service afficherait un état que le serveur ne gardera pas.
  .refine((values) => values.type === "good" || !values.trackStock, {
    path: ["trackStock"],
    message: "validation.stockGoodsOnly",
  });

export type ProductFormValues = z.infer<typeof productFormSchema>;
