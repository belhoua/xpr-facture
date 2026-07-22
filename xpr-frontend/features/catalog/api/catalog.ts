import {
  categoryListSchema,
  categorySchema,
  type Category,
  type CategoryFormValues,
  type CategoryList,
} from "@/features/catalog/schemas/category";
import {
  productListSchema,
  productSchema,
  taxRateListSchema,
  type Product,
  type ProductFormValues,
  type ProductList,
  type ProductType,
  type TaxRate,
} from "@/features/catalog/schemas/product";
import { api, ensureCsrfCookie } from "@/lib/api/client";

export interface ProductFilters {
  search: string;
  type: ProductType | "all";
  categoryId: string | "all";
}

export const catalogKeys = {
  all: ["catalog"] as const,
  products: () => [...catalogKeys.all, "products"] as const,
  productList: (filters: ProductFilters) =>
    [...catalogKeys.products(), filters] as const,
  categories: () => [...catalogKeys.all, "categories"] as const,
  categoryList: (search: string) =>
    [...catalogKeys.categories(), search] as const,
  taxRates: () => [...catalogKeys.all, "tax-rates"] as const,
};

/* ------------------------------------------------------------------ Taux */

/**
 * Référentiel de TVA. Il ne bouge qu'à la marge (un taux ajouté par la
 * société), d'où un `staleTime` long côté appelant : le refetch systématique
 * d'une liste figée à chaque ouverture de formulaire n'apporte rien.
 */
export async function fetchTaxRates(): Promise<readonly TaxRate[]> {
  const { data } = await api.get("/tax-rates");

  return taxRateListSchema.parse(data).data;
}

/* ------------------------------------------------------------- Catégories */

export async function fetchCategories(search = ""): Promise<CategoryList> {
  const { data } = await api.get("/categories", {
    params: { search: search || undefined },
  });

  return categoryListSchema.parse(data);
}

/** "" ⇒ `null` : le serveur distingue « champ vide » de « champ absent ». */
function toCategoryPayload(values: CategoryFormValues) {
  return {
    name: values.name.trim(),
    description: values.description.trim() || null,
    color: values.color || null,
    isActive: values.isActive,
  };
}

export async function createCategory(
  values: CategoryFormValues,
): Promise<Category> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/categories", toCategoryPayload(values));

  return categorySchema.parse(data);
}

export async function updateCategory(
  id: string,
  values: CategoryFormValues,
): Promise<Category> {
  await ensureCsrfCookie();

  const { data } = await api.patch(
    `/categories/${id}`,
    toCategoryPayload(values),
  );

  return categorySchema.parse(data);
}

/**
 * ARCHIVAGE et non suppression : les articles de la catégorie restent au
 * catalogue et les documents déjà émis restent lisibles (§3).
 */
export async function archiveCategory(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/categories/${id}`);
}

/* ---------------------------------------------------------------- Articles */

export async function fetchProducts(
  filters: ProductFilters,
): Promise<ProductList> {
  const { data } = await api.get("/products", {
    params: {
      search: filters.search || undefined,
      type: filters.type === "all" ? undefined : filters.type,
      categoryId: filters.categoryId === "all" ? undefined : filters.categoryId,
      perPage: 50,
    },
  });

  return productListSchema.parse(data);
}

/**
 * Unique frontière où les MAD saisis deviennent des centimes entiers — aucun
 * flottant ne circule au-delà (§7). `costPrice` à 0 vaut « non renseigné » :
 * un prix de revient nul fausserait la marge affichée.
 */
function toProductPayload(values: ProductFormValues) {
  return {
    type: values.type,
    name: values.name.trim(),
    reference: values.reference.trim() || null,
    description: values.description.trim() || null,
    unit: values.unit.trim() || null,
    categoryId: values.categoryId || null,
    taxRateId: values.taxRateId || null,
    unitPriceCents: Math.round(values.unitPrice * 100),
    costPriceCents:
      values.costPrice > 0 ? Math.round(values.costPrice * 100) : null,
    trackStock: values.type === "good" && values.trackStock,
    isActive: values.isActive,
  };
}

export async function createProduct(
  values: ProductFormValues,
): Promise<Product> {
  await ensureCsrfCookie();

  const { data } = await api.post("/products", toProductPayload(values));

  return productSchema.parse(data);
}

export async function updateProduct(
  id: string,
  values: ProductFormValues,
): Promise<Product> {
  await ensureCsrfCookie();

  const { data } = await api.patch(`/products/${id}`, toProductPayload(values));

  return productSchema.parse(data);
}

/** Archivage : une fiche référencée par un document émis ne se supprime pas. */
export async function archiveProduct(id: string): Promise<void> {
  await ensureCsrfCookie();

  await api.delete(`/products/${id}`);
}
