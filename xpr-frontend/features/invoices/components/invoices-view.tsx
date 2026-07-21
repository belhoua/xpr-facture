"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Ban, FileText, MoreHorizontal, Pencil, Plus, Search, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import {
  DOCUMENT_STATUSES,
  StatusBadge,
  type DocumentStatus,
} from "@/components/patterns/status-badge";
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
import { InvoiceFormDialog } from "@/features/invoices/components/invoice-form-dialog";
import {
  cancelInvoice,
  deleteInvoice,
  fetchInvoices,
  invoiceKeys,
} from "@/features/invoices/api/invoices";
import {
  isInvoiceCancellable,
  isInvoiceEditable,
  type Invoice,
} from "@/features/invoices/schemas/invoice";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";

/**
 * Liste des factures : recherche, filtre de statut, et CRUD complet dans le
 * respect de l'immuabilité fiscale (§3) — seuls les brouillons se modifient ou
 * se suppriment, les factures validées ne peuvent qu'être annulées.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `invoiceKeys.all`, ce qui rafraîchit la combinaison affichée sans
 * qu'on ait à deviner comment patcher chaque cache filtré.
 */
export function InvoicesView() {
  const t = useTranslations("invoices");
  const tStatus = useTranslations("status");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState<DocumentStatus | "all">("all");

  // État des dialogues. `editing === null` + `formOpen` = création ; sinon édition.
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Invoice | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Invoice | null>(null);
  const [cancelTarget, setCancelTarget] = useState<Invoice | null>(null);

  const filters = { search, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: invoiceKeys.list(filters),
    queryFn: () => fetchInvoices(filters),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: invoiceKeys.all });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteInvoice(id),
    onSuccess: async () => {
      await invalidate();
      setDeleteTarget(null);
    },
  });

  const cancelMutation = useMutation({
    mutationFn: (id: string) => cancelInvoice(id),
    onSuccess: async () => {
      await invalidate();
      setCancelTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (invoice: Invoice) => {
    setEditing(invoice);
    setFormOpen(true);
  };

  const columns: readonly Column<Invoice>[] = [
    {
      id: "number",
      header: t("columns.number"),
      cell: (row) => (
        <span className="tabular font-medium">{row.number ?? "—"}</span>
      ),
    },
    { id: "client", header: t("columns.client"), cell: (row) => row.clientName },
    {
      id: "issuedAt",
      header: t("columns.issuedAt"),
      hideBelow: "md",
      cell: (row) =>
        row.issuedAt ? (
          <span className="text-muted-foreground">
            {formatDate(row.issuedAt, locale)}
          </span>
        ) : (
          "—"
        ),
    },
    {
      id: "dueAt",
      header: t("columns.dueAt"),
      hideBelow: "lg",
      cell: (row) =>
        row.dueAt ? (
          <span className="text-muted-foreground">
            {formatDate(row.dueAt, locale)}
          </span>
        ) : (
          "—"
        ),
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
        <span className="amount font-medium">
          {formatMoney(row.totalCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "actions",
      header: t("columns.actions"),
      align: "end",
      cell: (row) => {
        const editable = isInvoiceEditable(row);
        const cancellable = isInvoiceCancellable(row);

        // Une facture verrouillée et non annulable (payée, annulée) n'a aucune
        // action : on n'affiche pas un menu vide.
        if (!editable && !cancellable) {
          return <span className="text-muted-foreground/60 text-xs">—</span>;
        }

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t("actions.open")}
                className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
              >
                <MoreHorizontal aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
              {editable && (
                <DropdownMenuItem onSelect={() => openEdit(row)}>
                  <Pencil aria-hidden />
                  {t("actions.edit")}
                </DropdownMenuItem>
              )}
              {cancellable && (
                <DropdownMenuItem onSelect={() => setCancelTarget(row)}>
                  <Ban aria-hidden />
                  {t("actions.cancel")}
                </DropdownMenuItem>
              )}
              {editable && (
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => setDeleteTarget(row)}
                >
                  <Trash2 aria-hidden />
                  {t("actions.delete")}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
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

        <Select
          value={status}
          onValueChange={(value) => setStatus(value as DocumentStatus | "all")}
        >
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{tCommon("all")}</SelectItem>
            {DOCUMENT_STATUSES.map((value) => (
              <SelectItem key={value} value={value}>
                {tStatus(value)}
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
        empty={{
          icon: FileText,
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

      <InvoiceFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        invoice={editing}
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

      <ConfirmDialog
        open={cancelTarget !== null}
        onOpenChange={(open) => !open && setCancelTarget(null)}
        title={t("cancelConfirm.title")}
        description={t("cancelConfirm.description")}
        confirmLabel={t("cancelConfirm.confirm")}
        pending={cancelMutation.isPending}
        onConfirm={() => cancelTarget && cancelMutation.mutate(cancelTarget.id)}
      />
    </>
  );
}
