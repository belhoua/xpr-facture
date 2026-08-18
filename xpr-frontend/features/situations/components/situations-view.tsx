"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { ClipboardList, Pencil, Plus, Printer, Search, Trash2 } from "lucide-react";
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
import type { Document } from "@/features/documents/schemas/document";
import {
  deleteSituation,
  fetchSituations,
  situationKeys,
  type SituationFilters,
} from "@/features/situations/api/situations";
import { useClientProjects } from "@/features/projects/hooks/use-client-projects";
import { SituationStatusBadge } from "@/features/situations/components/situation-status-badge";
import { SITUATION_STATUSES } from "@/features/situations/schemas/situation";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link, useRouter } from "@/lib/i18n/navigation";

/** Filtres proposés : le cycle de règlement, plus les situations annulées. */
/** Sentinelle « tous les projets » : Radix interdit la chaîne vide en valeur. */
const ALL_PROJECTS = "all";

const STATUS_FILTERS = [...SITUATION_STATUSES, "cancelled"] as const;

/**
 * Liste des situations.
 *
 * Réutilise `DataTable`, qui porte déjà les quatre états imposés par §6
 * (chargement / vide / erreur / succès) — aucun écran métier ne les réécrit.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query : changer une
 * borne de date refait la requête sans code de synchronisation. Les mutations
 * invalident `situationKeys.all` plutôt que de patcher chaque cache filtré,
 * les indicateurs de l'écran client compris.
 */
export function SituationsView() {
  const t = useTranslations("situations");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const router = useRouter();
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [projectId, setProjectId] = useState(ALL_PROJECTS);
  // `"all"` demande TOUT le répertoire de projets : cet écran ne présuppose
  // aucun client, contrairement au formulaire de saisie.
  const { projects } = useClientProjects(ALL_PROJECTS);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<Document | null>(null);

  const filters: SituationFilters = {
    search,
    status,
    // « tous » est un état de l'interface, pas un filtre serveur.
    projectId: projectId === ALL_PROJECTS ? "" : projectId,
    from,
    to,
  };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: situationKeys.list(filters),
    queryFn: () => fetchSituations(filters),
  });

  const removal = useMutation({
    mutationFn: (id: string) => deleteSituation(id),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: situationKeys.all });
      setDeleteTarget(null);
    },
  });

  const columns: readonly Column<Document>[] = [
    {
      id: "number",
      header: t("table.number"),
      cell: (row) => <span className="amount font-medium">{row.number ?? "—"}</span>,
    },
    {
      id: "issuedAt",
      header: t("table.date"),
      cell: (row) => (row.issuedAt ? formatDate(row.issuedAt, locale) : "—"),
      hideBelow: "md",
    },
    {
      id: "client",
      header: t("table.client"),
      cell: (row) => <span className="truncate">{row.clientName}</span>,
    },
    {
      id: "subject",
      header: t("table.subject"),
      cell: (row) => (
        <span className="text-muted-foreground truncate">{row.subject ?? "—"}</span>
      ),
      hideBelow: "lg",
    },
    {
      id: "total",
      header: t("table.total"),
      align: "end",
      cell: (row) => (
        <span className="amount">{formatMoney(row.totalCents, locale, row.currency)}</span>
      ),
    },
    {
      id: "paid",
      header: t("table.advance"),
      align: "end",
      cell: (row) => (
        <span className="amount text-muted-foreground">
          {formatMoney(row.paidCents, locale, row.currency)}
        </span>
      ),
      hideBelow: "md",
    },
    {
      id: "status",
      header: t("table.status"),
      cell: (row) => <SituationStatusBadge status={row.status} />,
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        // `stopPropagation` : la ligne entière est cliquable (elle ouvre
        // l'édition), les actions ne doivent pas déclencher les deux.
        <div
          className="flex items-center justify-end gap-1"
          onClick={(event) => event.stopPropagation()}
        >
          <Button
            variant="ghost"
            size="icon"
            aria-label={tCommon("edit")}
            onClick={() => router.push(`/situations/${row.id}/edit`)}
          >
            <Pencil className="size-4" aria-hidden />
          </Button>
          <Button
            variant="ghost"
            size="icon"
            aria-label={t("actions.print")}
            asChild
          >
            <Link href={`/situations/${row.id}/print`}>
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
          <Button asChild>
            <Link href="/situations/create">
              <Plus className="size-4" aria-hidden />
              {t("actions.create")}
            </Link>
          </Button>
        }
      />

      {/* `print:hidden` : les filtres n'ont aucun sens sur une feuille. */}
      <div className="mb-4 flex flex-wrap items-center gap-2 print:hidden">
        <div className="relative min-w-56 flex-1">
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

        {/* Filtre par PROJET. Cet écran n'a pas de client choisi au
            préalable : on liste donc tout le répertoire de projets, et le nom
            du client accompagne chaque titre — deux clients peuvent nommer
            leur chantier de la même façon, et le titre seul ne trancherait
            pas. */}
        <Select value={projectId} onValueChange={setProjectId}>
          <SelectTrigger className="w-56" aria-label={t("filters.project")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_PROJECTS}>{t("filters.allProjects")}</SelectItem>
            {projects.map((project) => (
              <SelectItem key={project.id} value={project.id}>
                {project.title}
                {project.clientName ? ` — ${project.clientName}` : ""}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="w-40" aria-label={t("table.status")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{tCommon("all")}</SelectItem>
            {STATUS_FILTERS.map((value) => (
              <SelectItem key={value} value={value}>
                {t(`statusFilter.${value}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Input
          type="date"
          value={from}
          onChange={(event) => setFrom(event.target.value)}
          aria-label={t("filters.from")}
          className="w-auto"
        />
        <Input
          type="date"
          value={to}
          onChange={(event) => setTo(event.target.value)}
          aria-label={t("filters.to")}
          className="w-auto"
        />
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        onRowClick={(row) => router.push(`/situations/${row.id}/edit`)}
        empty={{
          icon: ClipboardList,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button asChild size="sm">
              <Link href="/situations/create">
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
          number: deleteTarget?.number ?? "",
        })}
        confirmLabel={tCommon("delete")}
        variant="destructive"
        pending={removal.isPending}
        onConfirm={() => deleteTarget && removal.mutate(deleteTarget.id)}
      />
    </>
  );
}
