"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileText, MoreHorizontal, Pencil, Plus, Search, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { StatusBadge } from "@/components/patterns/status-badge";
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
import { catalogKeys, fetchProducts, fetchTaxRates } from "@/features/catalog/api/catalog";
import {
  deleteDocument,
  documentKeys,
  fetchDocuments,
} from "@/features/documents/api/documents";
import { DocumentDetailSheet } from "@/features/documents/components/document-detail-sheet";
import { DocumentFormDialog } from "@/features/documents/components/document-form-dialog";
import {
  assignableStatuses,
  isEditable,
  type Document,
  type DocumentType,
} from "@/features/documents/schemas/document";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";

/** Une heure : le référentiel de TVA et le catalogue ne bougent pas en séance. */
const REFERENCE_STALE_TIME = 60 * 60 * 1000;

/**
 * Liste des documents d'un TYPE donné — factures, devis, avoirs partagent cet
 * écran, seule leur enveloppe change. Le type n'est pas un filtre d'interface
 * mais un paramètre de la page : « Factures » et « Devis » sont deux écrans
 * distincts dans la navigation, avec chacun leur cycle de vie.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `documentKeys.all` plutôt que de patcher chaque cache filtré.
 *
 * Cet écran PRÉCHARGE le catalogue et les taux de TVA : ils sont figés à
 * l'échelle d'une séance, et les avoir en cache évite au formulaire de
 * s'ouvrir sur des sélecteurs vides.
 */
export function DocumentsView({ type }: { type: DocumentType }) {
  const t = useTranslations("documents");
  const tStatus = useTranslations("status");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Document | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Document | null>(null);

  const filters = { type, search, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: documentKeys.list(filters),
    queryFn: () => fetchDocuments(filters),
  });

  useQuery({
    queryKey: catalogKeys.taxRates(),
    queryFn: fetchTaxRates,
    staleTime: REFERENCE_STALE_TIME,
  });

  useQuery({
    queryKey: catalogKeys.productList({
      search: "",
      type: "all",
      categoryId: "all",
    }),
    queryFn: () => fetchProducts({ search: "", type: "all", categoryId: "all" }),
    staleTime: REFERENCE_STALE_TIME,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteDocument(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: documentKeys.all });
      setDeleteTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (document: Document) => {
    setEditing(document);
    setFormOpen(true);
  };

  /** Statuts filtrables : ceux du cycle du type, brouillon et annulé compris. */
  const statusFilters = ["all", "draft", ...assignableStatuses(type), "cancelled"];

  const columns: readonly Column<Document>[] = [
    {
      id: "number",
      header: t("columns.number"),
      cell: (row) => (
        <span className="tabular font-medium">
          {row.number ?? (
            <span className="text-muted-foreground">{t("draftLabel")}</span>
          )}
        </span>
      ),
    },
    {
      id: "client",
      header: t("columns.client"),
      cell: (row) => row.clientName,
    },
    {
      id: "issuedAt",
      header: t("columns.issuedAt"),
      hideBelow: "md",
      cell: (row) => (row.issuedAt ? formatDate(row.issuedAt, locale) : "—"),
    },
    {
      id: "dueAt",
      header: t("columns.dueAt"),
      hideBelow: "lg",
      cell: (row) => (row.dueAt ? formatDate(row.dueAt, locale) : "—"),
    },
    {
      id: "status",
      header: t("columns.status"),
      cell: (row) => (
        <StatusBadge status={row.status} label={tStatus(row.status)} />
      ),
    },
    {
      id: "total",
      header: t("columns.total"),
      align: "end",
      cell: (row) => (
        <span className="tabular font-medium">
          {formatMoney(row.totalCents, locale, row.currency)}
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
              aria-label={t("actions.open")}
              // Le clic sur l'action ne doit pas aussi ouvrir le détail :
              // la ligne entière est cliquable.
              onClick={(event) => event.stopPropagation()}
              className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
            >
              <MoreHorizontal aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48">
            <DropdownMenuItem onSelect={() => setDetailId(row.id)}>
              <FileText aria-hidden />
              {t("actions.view")}
            </DropdownMenuItem>

            {/* Immuabilité fiscale (§3) : seuls les brouillons se modifient
                ou se suppriment. Un document émis ne peut qu'être annulé,
                depuis le panneau de détail. */}
            {isEditable(row) && (
              <>
                <DropdownMenuItem onSelect={() => openEdit(row)}>
                  <Pencil aria-hidden />
                  {t("actions.edit")}
                </DropdownMenuItem>
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => setDeleteTarget(row)}
                >
                  <Trash2 aria-hidden />
                  {t("actions.delete")}
                </DropdownMenuItem>
              </>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t(`title.${type}`)}
        description={t(`description.${type}`)}
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus aria-hidden />
            {t(`create.${type}`)}
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
            placeholder={t("searchPlaceholder")}
            aria-label={tCommon("search")}
            className="ps-8"
          />
        </div>

        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {statusFilters.map((value) => (
              <SelectItem key={value} value={value}>
                {value === "all" ? tCommon("all") : tStatus(value)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        onRowClick={(row) => setDetailId(row.id)}
        empty={{
          icon: FileText,
          title: t(`empty.${type}.title`),
          description: t(`empty.${type}.description`),
          action: (
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t(`create.${type}`)}
            </Button>
          ),
        }}
      />

      <DocumentFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        type={type}
        document={editing}
      />

      <DocumentDetailSheet
        documentId={detailId}
        onOpenChange={(open) => !open && setDetailId(null)}
        onEdit={(document) => {
          setDetailId(null);
          openEdit(document);
        }}
        // Conversion et avoir produisent un NOUVEAU document, d'un autre type
        // que celui de cet écran : on bascule le panneau dessus plutôt que de
        // laisser l'utilisateur le chercher dans une liste qui ne le contient
        // pas.
        onConverted={(created) => setDetailId(created.id)}
      />

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description")}
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />
    </>
  );
}
