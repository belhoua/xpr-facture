"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { FileSignature, Pencil, Plus, Printer, Search, Trash2 } from "lucide-react";
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
  deleteConvention,
  fetchConventions,
  type ConventionFilters,
} from "@/features/conventions/api/conventions";
import { ConventionStatusBadge } from "@/features/conventions/components/convention-status-badge";
import {
  CONVENTION_STATUSES,
  isConventionDeletable,
  type Convention,
} from "@/features/conventions/schemas/convention";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link, useRouter } from "@/lib/i18n/navigation";

/**
 * Liste des contrats de convention.
 *
 * Réutilise `DataTable`, qui porte déjà les quatre états imposés par §6
 * (chargement / vide / erreur / succès). Les filtres font partie de la CLÉ de
 * requête TanStack Query ; les mutations invalident `conventionKeys.all` plutôt
 * que de patcher chaque cache filtré.
 */
export function ConventionsView() {
  const t = useTranslations("conventions");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [deleteTarget, setDeleteTarget] = useState<Convention | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const filters: ConventionFilters = { search, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: conventionKeys.list(filters),
    queryFn: () => fetchConventions(filters),
  });

  const removal = useMutation({
    mutationFn: (id: string) => deleteConvention(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: conventionKeys.all });
      setDeleteTarget(null);
      setActionError(null);
    },
    onError: (cause) => {
      // Le serveur reste juge : il répond 409 sur une convention signée. On
      // réaffiche SON message plutôt que d'en inventer un depuis l'interface.
      const problem = toApiProblem(cause);
      setActionError(problem.detail ?? problem.title);
      setDeleteTarget(null);
    },
  });

  const columns: readonly Column<Convention>[] = [
    {
      id: "dossier",
      header: t("table.dossier"),
      cell: (row) => (
        <span className="amount font-medium">
          {row.dossierNumber ?? (
            <span className="text-muted-foreground">{t("table.noDossier")}</span>
          )}
        </span>
      ),
    },
    {
      id: "owner",
      header: t("table.owner"),
      cell: (row) => <span className="truncate">{row.ownerName}</span>,
    },
    {
      id: "project",
      header: t("table.project"),
      hideBelow: "lg",
      cell: (row) => (
        <span className="text-muted-foreground line-clamp-1">
          {row.projectDescription}
        </span>
      ),
    },
    {
      id: "issuedAt",
      header: t("table.date"),
      hideBelow: "md",
      cell: (row) => (row.issuedAt ? formatDate(row.issuedAt, locale) : "—"),
    },
    {
      id: "total",
      header: t("table.fees"),
      align: "end",
      cell: (row) => (
        <span className="amount">
          {formatMoney(row.totalCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "status",
      header: t("table.status"),
      cell: (row) => <ConventionStatusBadge status={row.status} />,
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        // `stopPropagation` : la ligne entière ouvre l'édition, les actions ne
        // doivent pas déclencher les deux.
        <div
          className="flex items-center justify-end gap-1"
          onClick={(event) => event.stopPropagation()}
        >
          <Button
            variant="ghost"
            size="icon"
            aria-label={tCommon("edit")}
            onClick={() => router.push(`/conventions/${row.id}/edit`)}
          >
            <Pencil className="size-4" aria-hidden />
          </Button>
          <Button variant="ghost" size="icon" aria-label={t("actions.print")} asChild>
            <Link href={`/conventions/${row.id}/print`}>
              <Printer className="size-4" aria-hidden />
            </Link>
          </Button>
          {/* Une convention SIGNÉE s'annule, elle ne se supprime pas : le bouton
              disparaît plutôt que d'ouvrir une confirmation vouée au 409. */}
          {isConventionDeletable(row) ? (
            <Button
              variant="ghost"
              size="icon"
              aria-label={tCommon("delete")}
              className="text-destructive hover:text-destructive"
              onClick={() => setDeleteTarget(row)}
            >
              <Trash2 className="size-4" aria-hidden />
            </Button>
          ) : null}
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
          <Button asChild>
            <Link href="/conventions/create">
              <Plus className="size-4" aria-hidden />
              {t("actions.create")}
            </Link>
          </Button>
        }
      />

      {actionError !== null && (
        <p className="text-destructive mb-3 text-sm" role="alert">
          {actionError}
        </p>
      )}

      {/* `print:hidden` : les filtres n'ont aucun sens sur une feuille. */}
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
            {CONVENTION_STATUSES.map((value) => (
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
        onRowClick={(row) => router.push(`/conventions/${row.id}/edit`)}
        empty={{
          icon: FileSignature,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button asChild size="sm">
              <Link href="/conventions/create">
                <Plus className="size-4" aria-hidden />
                {t("actions.create")}
              </Link>
            </Button>
          ),
        }}
      />

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description", {
          owner: deleteTarget?.ownerName ?? "",
        })}
        confirmLabel={tCommon("delete")}
        variant="destructive"
        pending={removal.isPending}
        onConfirm={() => deleteTarget && removal.mutate(deleteTarget.id)}
      />
    </>
  );
}
