"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Archive, MoreHorizontal, Pencil, Plus, Search, Tags } from "lucide-react";
import { useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import {
  archiveCategory,
  catalogKeys,
  fetchCategories,
} from "@/features/catalog/api/catalog";
import type { Category } from "@/features/catalog/schemas/category";
import { toApiProblem } from "@/lib/api/client";
import { useDeferredMount } from "@/lib/use-deferred-mount";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/**
 * Panneau chargé à la demande : son code — formulaire complet, validation
 * Zod, sélecteurs — n'a aucune raison de partir avec la liste, qui s'ouvre
 * sur un tableau. Le téléchargement a lieu à la première ouverture
 * (cf. `useDeferredMount`).
 */
const CategoryFormDialog = dynamic(
  () => import("@/features/catalog/components/category-form-dialog").then((m) => m.CategoryFormDialog),
  { ssr: false },
);

/**
 * Catégories de services.
 *
 * ── Ce que cet écran ne fait plus (2026-08-18) ───────────────────────────
 *
 * Il portait deux onglets, « Articles » et « Catégories ». L'onglet Articles a
 * été retiré à la demande de l'exploitant : la société ne commercialise aucun
 * bien physique, et les prestations qu'elle vend se gèrent sur l'écran
 * `/services`, qui leur est dédié. Deux écrans montraient donc la même liste
 * sous deux noms, dont l'un parlait de « produits » d'une entreprise qui n'en
 * a pas.
 *
 * Les onglets sont tombés avec lui : un onglet unique n'est pas une navigation,
 * c'est un titre déguisé en bouton.
 *
 * ⚠️ Rien n'a été supprimé côté serveur. Les articles restent créés, modifiés
 * et facturés par `/services` et par les lignes de document ; c'est bien la
 * VUE qui disparaît, pas l'entité.
 */
export function CatalogView() {
  const t = useTranslations("catalog");
  const tCommon = useTranslations("common");
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Category | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<Category | null>(null);

  const categoriesQuery = useQuery({
    queryKey: catalogKeys.categoryList(debouncedSearch),
    queryFn: () => fetchCategories(search),
  });

  const archiveMutation = useMutation({
    mutationFn: (id: string) => archiveCategory(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKeys.all });
      setArchiveTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const columns: readonly Column<Category>[] = [
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
      header: t("categories.columns.services"),
      align: "end",
      cell: (row) => (
        // `serviceCount` absent ≠ zéro : le serveur ne le renvoie que s'il a
        // fait le décompte. Afficher « 0 » sur une catégorie pleine serait pire
        // que de ne rien afficher.
        <span className="tabular">{row.serviceCount ?? "—"}</span>
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

  const formOpenMounted = useDeferredMount(formOpen);

  return (
    <>
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus aria-hidden />
            {t("categories.create")}
          </Button>
        }
      />

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:max-w-80">
          <Search
            className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
            aria-hidden
          />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("categories.searchPlaceholder")}
            aria-label={tCommon("search")}
            className="ps-8"
          />
        </div>
      </div>

      <DataTable
        rows={categoriesQuery.data?.data ?? []}
        columns={columns}
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
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t("categories.create")}
            </Button>
          ),
        }}
      />

      {formOpenMounted && (
        <CategoryFormDialog
          open={formOpen}
          onOpenChange={setFormOpen}
          category={editing}
        />
      )}

      <ConfirmDialog
        open={archiveTarget !== null}
        onOpenChange={(open) => !open && setArchiveTarget(null)}
        title={t("archive.categoryTitle")}
        description={t("archive.categoryDescription")}
        confirmLabel={t("archive.confirm")}
        pending={archiveMutation.isPending}
        onConfirm={() =>
          archiveTarget && archiveMutation.mutate(archiveTarget.id)
        }
      />
    </>
  );
}
