"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FolderInput, Pencil, Plus, Printer, Search, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  conventionKeys,
  deleteDeposit,
  depositKeys,
  fetchDeposits,
  type DepositFilters,
} from "@/features/conventions/api/conventions";
import { DepositStatusBadge } from "@/features/conventions/components/convention-status-badge";
import { DepositFormDialog } from "@/features/conventions/components/deposit-form-dialog";
import {
  DEPOSIT_STATUSES,
  type FileDeposit,
} from "@/features/conventions/schemas/convention";
import { toApiProblem } from "@/lib/api/client";
import { formatDate } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Suivi TRANSVERSE des dépôts de dossier : tous projets confondus.
 *
 * C'est l'écran de la question du matin — « quels dossiers attendent encore une
 * réponse » — qui traverse les conventions et ne se répond donc pas depuis la
 * fiche de l'une d'elles. La fiche d'une convention garde, elle, le suivi de SON
 * dossier (cf. `ConventionDeposits`).
 */
export function DepositsView() {
  const t = useTranslations("deposits");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<FileDeposit | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<FileDeposit | null>(null);

  const filters: DepositFilters = { search, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: depositKeys.list(filters),
    queryFn: () => fetchDeposits(filters),
  });

  const removal = useMutation({
    mutationFn: (id: string) => deleteDeposit(id),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: depositKeys.all }),
        queryClient.invalidateQueries({ queryKey: conventionKeys.all }),
      ]);
      setDeleteTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const columns: readonly Column<FileDeposit>[] = [
    {
      id: "reference",
      header: t("table.reference"),
      cell: (row) => <span className="amount font-medium">{row.reference}</span>,
    },
    {
      id: "project",
      header: t("table.project"),
      cell: (row) => (
        <span className="line-clamp-1">
          {/* La convention peut avoir été archivée : le dépôt lui survit, et
              taire son absence laisserait une cellule vide inexplicable. */}
          {row.convention?.projectDescription ?? (
            <span className="text-muted-foreground">{t("table.noConvention")}</span>
          )}
        </span>
      ),
    },
    {
      id: "organisation",
      header: t("table.organisation"),
      hideBelow: "md",
      cell: (row) => <span className="truncate">{row.organisation}</span>,
    },
    {
      id: "depositedAt",
      header: t("table.depositedAt"),
      cell: (row) => formatDate(row.depositedAt, locale),
    },
    {
      id: "status",
      header: t("table.status"),
      cell: (row) => <DepositStatusBadge status={row.status} />,
    },
    {
      id: "decidedAt",
      header: t("table.decidedAt"),
      hideBelow: "lg",
      cell: (row) => (row.decidedAt ? formatDate(row.decidedAt, locale) : "—"),
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        <div
          className="flex items-center justify-end gap-1"
          onClick={(event) => event.stopPropagation()}
        >
          <Button
            variant="ghost"
            size="icon"
            aria-label={tCommon("edit")}
            onClick={() => {
              setEditing(row);
              setFormOpen(true);
            }}
          >
            <Pencil className="size-4" aria-hidden />
          </Button>
          <Button variant="ghost" size="icon" aria-label={t("actions.print")} asChild>
            <Link href={`/deposits/${row.id}/print`}>
              <Printer className="size-4" aria-hidden />
            </Link>
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={tCommon("delete")}
            className="text-destructive hover:text-destructive"
            onClick={() => setDeleteTarget(row)}
          >
            <Trash2 className="size-4" aria-hidden />
          </Button>
        </div>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" aria-hidden />
            {t("actions.create")}
          </Button>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
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
          <SelectTrigger className="w-44" aria-label={t("table.status")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{tCommon("all")}</SelectItem>
            {DEPOSIT_STATUSES.map((value) => (
              <SelectItem key={value} value={value}>
                {t(`status.${value}`)}
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
          icon: FolderInput,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button size="sm" onClick={openCreate}>
              <Plus className="size-4" aria-hidden />
              {t("actions.create")}
            </Button>
          ),
        }}
      />

      {/* Sans `conventionId`, la boîte fait CHOISIR le dossier : depuis cet
          écran, rien ne dit de quel projet il s'agit. */}
      <DepositFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        deposit={editing}
      />

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description", {
          reference: deleteTarget?.reference ?? "",
        })}
        confirmLabel={tCommon("delete")}
        variant="destructive"
        pending={removal.isPending}
        onConfirm={() => deleteTarget && removal.mutate(deleteTarget.id)}
      />
    </>
  );
}
