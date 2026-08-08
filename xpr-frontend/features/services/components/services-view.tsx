"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Archive, MoreHorizontal, Pencil, Plus, Search, Wrench } from "lucide-react";
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
import {
  archiveProduct,
  catalogKeys,
  fetchCategories,
  fetchProducts,
} from "@/features/catalog/api/catalog";
import type { Product } from "@/features/catalog/schemas/product";
import { ServiceFormDialog } from "@/features/services/components/service-form-dialog";
import { toProductFilters } from "@/features/services/schemas/service";
import { toApiProblem } from "@/lib/api/client";
import { formatMoney } from "@/lib/format";

/**
 * Écran Services : le catalogue restreint aux prestations.
 *
 * C'est une VUE, pas une entité : elle interroge `/products?type=service` et
 * écrit par la même API. Le catalogue complet reste accessible sous /catalog,
 * biens compris. Les deux écrans partagent donc leur cache TanStack Query —
 * créer un service ici rafraîchit aussi le catalogue.
 *
 * Un service ne se SUPPRIME pas : il est archivé, sans quoi les documents déjà
 * émis qui le référencent deviendraient illisibles (§3).
 */
export function ServicesView() {
  const t = useTranslations("services");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [categoryId, setCategoryId] = useState<string>("all");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Product | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<Product | null>(null);

  const filters = toProductFilters({ search, categoryId });
  const servicesQuery = useQuery({
    queryKey: catalogKeys.productList(filters),
    queryFn: () => fetchProducts(filters),
  });

  const categoriesQuery = useQuery({
    queryKey: catalogKeys.categoryList(""),
    queryFn: () => fetchCategories(""),
  });

  const archiveMutation = useMutation({
    mutationFn: (id: string) => archiveProduct(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKeys.all });
      setArchiveTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const categories = categoriesQuery.data?.data ?? [];

  const columns: readonly Column<Product>[] = [
    {
      id: "reference",
      header: t("columns.reference"),
      hideBelow: "md",
      cell: (row) => (
        <span className="tabular text-muted-foreground text-xs">
          {row.reference ?? "—"}
        </span>
      ),
    },
    {
      id: "name",
      header: t("columns.name"),
      cell: (row) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.name}</span>
          {/* La référence réapparaît sous le nom en petit écran, où sa
              colonne est masquée : elle ne doit pas disparaître de la vue. */}
          {row.reference && (
            <span className="text-muted-foreground tabular text-xs md:hidden">
              {row.reference}
            </span>
          )}
        </div>
      ),
    },
    {
      id: "kind",
      header: t("columns.kind"),
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
      id: "unit",
      header: t("columns.unit"),
      hideBelow: "lg",
      cell: (row) => (
        <span className="text-muted-foreground">{row.unit ?? "—"}</span>
      ),
    },
    {
      id: "price",
      header: t("columns.price"),
      align: "end",
      cell: (row) => (
        <span className="tabular font-medium">
          {formatMoney(row.unitPriceCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "discount",
      header: t("columns.discount"),
      align: "end",
      hideBelow: "lg",
      cell: (row) =>
        // Une remise à 0 est le cas courant : l'afficher partout ferait du
        // bruit dans une liste dense (§11). Seule une remise réelle se voit.
        Number(row.defaultDiscountPercent) > 0 ? (
          <Badge variant="secondary" className="tabular">
            −{row.defaultDiscountPercent} %
          </Badge>
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      id: "tax",
      header: t("columns.tax"),
      align: "end",
      hideBelow: "md",
      cell: (row) => (
        <span className="tabular text-muted-foreground">
          {row.taxRateValue !== null ? `${row.taxRateValue} %` : "—"}
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
                setEditing(row);
                setFormOpen(true);
              }}
            >
              <Pencil aria-hidden />
              {tCommon("edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              variant="destructive"
              onSelect={() => setArchiveTarget(row)}
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
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus aria-hidden />
            {t("create")}
          </Button>
        }
      />

      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative min-w-56 flex-1 sm:max-w-72">
            <Search
              className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
              aria-hidden
            />
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t("searchPlaceholder")}
              aria-label={tCommon("search")}
              className="ps-8"
            />
          </div>

          <Select value={categoryId} onValueChange={setCategoryId}>
            <SelectTrigger className="w-44">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">{t("allKinds")}</SelectItem>
              {categories.map((category) => (
                <SelectItem key={category.id} value={category.id}>
                  {category.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <DataTable
          rows={servicesQuery.data?.data ?? []}
          columns={columns}
          rowKey={(row) => row.id}
          status={
            servicesQuery.isPending
              ? "pending"
              : servicesQuery.isError
                ? "error"
                : "success"
          }
          errorDetail={
            servicesQuery.isError
              ? toApiProblem(servicesQuery.error).detail
              : undefined
          }
          onRetry={() => void servicesQuery.refetch()}
          empty={{
            icon: Wrench,
            title: t("empty.title"),
            description: t("empty.description"),
            action: (
              <Button size="sm" onClick={openCreate}>
                <Plus aria-hidden />
                {t("create")}
              </Button>
            ),
          }}
        />
      </div>

      <ServiceFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        service={editing}
      />

      <ConfirmDialog
        open={archiveTarget !== null}
        onOpenChange={(open) => !open && setArchiveTarget(null)}
        title={t("archive.title")}
        description={t("archive.description")}
        confirmLabel={t("archive.confirm")}
        pending={archiveMutation.isPending}
        onConfirm={() => archiveTarget && archiveMutation.mutate(archiveTarget.id)}
      />
    </>
  );
}
