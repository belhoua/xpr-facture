"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Archive,
  MoreHorizontal,
  Package,
  Pencil,
  Plus,
  Search,
  Tags,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  archiveCategory,
  archiveProduct,
  catalogKeys,
  fetchCategories,
  fetchProducts,
} from "@/features/catalog/api/catalog";
import { CategoryFormDialog } from "@/features/catalog/components/category-form-dialog";
import { ProductFormDialog } from "@/features/catalog/components/product-form-dialog";
import type { Category } from "@/features/catalog/schemas/category";
import {
  PRODUCT_TYPES,
  type Product,
  type ProductType,
} from "@/features/catalog/schemas/product";
import { toApiProblem } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";

/**
 * Catalogue : articles et catégories dans un seul écran à deux onglets.
 *
 * Les deux vivent ensemble parce qu'on ne gère pas des catégories pour
 * elles-mêmes — on les crée en classant des articles. Deux entrées de
 * navigation distinctes obligeraient à faire des allers-retours pour une
 * opération qui n'en est qu'une.
 *
 * Ni l'un ni l'autre ne se SUPPRIME : archiver conserve la lisibilité des
 * documents déjà émis, qui référencent l'article par son identifiant (§3).
 */
export function CatalogView() {
  const t = useTranslations("catalog");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [type, setType] = useState<ProductType | "all">("all");
  const [categoryId, setCategoryId] = useState<string>("all");

  const [productFormOpen, setProductFormOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<Product | null>(null);
  const [productArchiveTarget, setProductArchiveTarget] =
    useState<Product | null>(null);

  const [categoryFormOpen, setCategoryFormOpen] = useState(false);
  const [editingCategory, setEditingCategory] = useState<Category | null>(null);
  const [categoryArchiveTarget, setCategoryArchiveTarget] =
    useState<Category | null>(null);

  const productFilters = { search, type, categoryId };
  const productsQuery = useQuery({
    queryKey: catalogKeys.productList(productFilters),
    queryFn: () => fetchProducts(productFilters),
  });

  const categoriesQuery = useQuery({
    queryKey: catalogKeys.categoryList(""),
    queryFn: () => fetchCategories(""),
  });

  const archiveProductMutation = useMutation({
    mutationFn: (id: string) => archiveProduct(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKeys.all });
      setProductArchiveTarget(null);
    },
  });

  const archiveCategoryMutation = useMutation({
    mutationFn: (id: string) => archiveCategory(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKeys.all });
      setCategoryArchiveTarget(null);
    },
  });

  const openCreateProduct = () => {
    setEditingProduct(null);
    setProductFormOpen(true);
  };

  const openCreateCategory = () => {
    setEditingCategory(null);
    setCategoryFormOpen(true);
  };

  const categories = categoriesQuery.data?.data ?? [];

  const productColumns: readonly Column<Product>[] = [
    {
      id: "name",
      header: t("products.columns.name"),
      cell: (row) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.name}</span>
          {row.reference && (
            <span className="text-muted-foreground tabular text-xs">
              {row.reference}
            </span>
          )}
        </div>
      ),
    },
    {
      id: "type",
      header: t("products.columns.type"),
      hideBelow: "md",
      cell: (row) => (
        <Badge variant="secondary">{t(`products.types.${row.type}`)}</Badge>
      ),
    },
    {
      id: "category",
      header: t("products.columns.category"),
      hideBelow: "md",
      cell: (row) =>
        row.categoryName ? (
          <span className="inline-flex items-center gap-1.5">
            <span
              aria-hidden
              className="size-2 rounded-full"
              style={{ backgroundColor: row.categoryColor ?? "currentColor" }}
            />
            {row.categoryName}
          </span>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      id: "tax",
      header: t("products.columns.tax"),
      align: "end",
      hideBelow: "lg",
      cell: (row) => (
        <span className="tabular text-muted-foreground">
          {row.taxRateValue !== null ? `${row.taxRateValue} %` : "—"}
        </span>
      ),
    },
    {
      id: "price",
      header: t("products.columns.price"),
      align: "end",
      cell: (row) => (
        <span className="tabular font-medium">
          {formatMoney(row.unitPriceCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={tCommon("actions")}
              className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
            >
              <MoreHorizontal aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-44">
            <DropdownMenuItem
              onSelect={() => {
                setEditingProduct(row);
                setProductFormOpen(true);
              }}
            >
              <Pencil aria-hidden />
              {tCommon("edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              variant="destructive"
              onSelect={() => setProductArchiveTarget(row)}
            >
              <Archive aria-hidden />
              {t("archive.action")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ];

  const categoryColumns: readonly Column<Category>[] = [
    {
      id: "name",
      header: t("categories.columns.name"),
      cell: (row) => (
        <span className="inline-flex items-center gap-2 font-medium">
          <span
            aria-hidden
            className="size-2.5 rounded-full"
            style={{ backgroundColor: row.color ?? "currentColor" }}
          />
          {row.name}
        </span>
      ),
    },
    {
      id: "description",
      header: t("categories.columns.description"),
      hideBelow: "md",
      cell: (row) => (
        <span className="text-muted-foreground">{row.description ?? "—"}</span>
      ),
    },
    {
      id: "count",
      header: t("categories.columns.products"),
      align: "end",
      cell: (row) => (
        // `productCount` absent ≠ zéro : le serveur ne le renvoie que s'il a
        // fait le décompte. Afficher « 0 » sur une catégorie pleine serait pire
        // que de ne rien afficher.
        <span className="tabular">{row.productCount ?? "—"}</span>
      ),
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={tCommon("actions")}
              className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
            >
              <MoreHorizontal aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-44">
            <DropdownMenuItem
              onSelect={() => {
                setEditingCategory(row);
                setCategoryFormOpen(true);
              }}
            >
              <Pencil aria-hidden />
              {tCommon("edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              variant="destructive"
              onSelect={() => setCategoryArchiveTarget(row)}
            >
              <Archive aria-hidden />
              {t("archive.action")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ];

  return (
    <>
      <PageHeader title={t("title")} description={t("description")} />

      <Tabs defaultValue="products">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <TabsList>
            <TabsTrigger value="products">{t("tabs.products")}</TabsTrigger>
            <TabsTrigger value="categories">{t("tabs.categories")}</TabsTrigger>
          </TabsList>
        </div>

        <TabsContent value="products" className="space-y-3">
          <div className="flex flex-wrap items-center gap-2">
            <div className="relative min-w-56 flex-1 sm:max-w-72">
              <Search
                className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
                aria-hidden
              />
              <Input
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                placeholder={t("products.searchPlaceholder")}
                aria-label={tCommon("search")}
                className="ps-8"
              />
            </div>

            <Select
              value={type}
              onValueChange={(value) => setType(value as ProductType | "all")}
            >
              <SelectTrigger className="w-36">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{tCommon("all")}</SelectItem>
                {PRODUCT_TYPES.map((value) => (
                  <SelectItem key={value} value={value}>
                    {t(`products.types.${value}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Select value={categoryId} onValueChange={setCategoryId}>
              <SelectTrigger className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{t("products.allCategories")}</SelectItem>
                {categories.map((category) => (
                  <SelectItem key={category.id} value={category.id}>
                    {category.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>

            <Button size="sm" className="ms-auto" onClick={openCreateProduct}>
              <Plus aria-hidden />
              {t("products.create")}
            </Button>
          </div>

          <DataTable
            rows={productsQuery.data?.data ?? []}
            columns={productColumns}
            rowKey={(row) => row.id}
            status={
              productsQuery.isPending
                ? "pending"
                : productsQuery.isError
                  ? "error"
                  : "success"
            }
            errorDetail={
              productsQuery.isError
                ? toApiProblem(productsQuery.error).detail
                : undefined
            }
            onRetry={() => void productsQuery.refetch()}
            empty={{
              icon: Package,
              title: t("products.empty.title"),
              description: t("products.empty.description"),
              action: (
                <Button size="sm" onClick={openCreateProduct}>
                  <Plus aria-hidden />
                  {t("products.create")}
                </Button>
              ),
            }}
          />
        </TabsContent>

        <TabsContent value="categories" className="space-y-3">
          <div className="flex justify-end">
            <Button size="sm" onClick={openCreateCategory}>
              <Plus aria-hidden />
              {t("categories.create")}
            </Button>
          </div>

          <DataTable
            rows={categories}
            columns={categoryColumns}
            rowKey={(row) => row.id}
            status={
              categoriesQuery.isPending
                ? "pending"
                : categoriesQuery.isError
                  ? "error"
                  : "success"
            }
            errorDetail={
              categoriesQuery.isError
                ? toApiProblem(categoriesQuery.error).detail
                : undefined
            }
            onRetry={() => void categoriesQuery.refetch()}
            empty={{
              icon: Tags,
              title: t("categories.empty.title"),
              description: t("categories.empty.description"),
              action: (
                <Button size="sm" onClick={openCreateCategory}>
                  <Plus aria-hidden />
                  {t("categories.create")}
                </Button>
              ),
            }}
          />
        </TabsContent>
      </Tabs>

      <ProductFormDialog
        open={productFormOpen}
        onOpenChange={setProductFormOpen}
        product={editingProduct}
      />

      <CategoryFormDialog
        open={categoryFormOpen}
        onOpenChange={setCategoryFormOpen}
        category={editingCategory}
      />

      <ConfirmDialog
        open={productArchiveTarget !== null}
        onOpenChange={(open) => !open && setProductArchiveTarget(null)}
        title={t("archive.productTitle")}
        description={t("archive.productDescription")}
        confirmLabel={t("archive.confirm")}
        pending={archiveProductMutation.isPending}
        onConfirm={() =>
          productArchiveTarget &&
          archiveProductMutation.mutate(productArchiveTarget.id)
        }
      />

      <ConfirmDialog
        open={categoryArchiveTarget !== null}
        onOpenChange={(open) => !open && setCategoryArchiveTarget(null)}
        title={t("archive.categoryTitle")}
        description={t("archive.categoryDescription")}
        confirmLabel={t("archive.confirm")}
        pending={archiveCategoryMutation.isPending}
        onConfirm={() =>
          categoryArchiveTarget &&
          archiveCategoryMutation.mutate(categoryArchiveTarget.id)
        }
      />
    </>
  );
}
