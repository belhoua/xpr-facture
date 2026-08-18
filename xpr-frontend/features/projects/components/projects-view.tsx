"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { FolderKanban, Plus, Search } from "lucide-react";
import { useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useState } from "react";

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
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import {
  fetchProjects,
  projectKeys,
  type ProjectFilters,
} from "@/features/projects/api/projects";
import { ProgressBar } from "@/features/projects/components/progress-bar";
import { ProjectStatusBadge } from "@/features/projects/components/project-status-badge";
import {
  PROJECT_STATUSES,
  type Project,
} from "@/features/projects/schemas/project";
import { toApiProblem } from "@/lib/api/client";
import { useDeferredMount } from "@/lib/use-deferred-mount";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/**
 * Panneau chargé à la demande : son code — formulaire complet, validation
 * Zod, sélecteurs — n'a aucune raison de partir avec la liste, qui s'ouvre
 * sur un tableau. Le téléchargement a lieu à la première ouverture
 * (cf. `useDeferredMount`).
 */
const ProjectDetailSheet = dynamic(
  () => import("@/features/projects/components/project-detail-sheet").then((m) => m.ProjectDetailSheet),
  { ssr: false },
);

const ProjectFormDialog = dynamic(
  () => import("@/features/projects/components/project-form-dialog").then((m) => m.ProjectFormDialog),
  { ssr: false },
);

/**
 * Avancement de projet : la liste, ses deux filtres, et le tiroir de détail.
 *
 * L'ordre est celui du serveur — du plus récent au plus ancien — et n'est pas
 * rejoué ici : trier à nouveau côté client ne trierait que la page reçue, ce
 * qui donnerait un ordre faux dès la seconde page, et faux sans le dire.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `projectKeys.all` plutôt que de patcher chaque cache filtré.
 */
export function ProjectsView() {
  const t = useTranslations("projects");
  const tCommon = useTranslations("common");

  const [search, setSearch] = useState("");

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);
  const [status, setStatus] = useState("all");
  const [partnerId, setPartnerId] = useState("all");
  const [detailId, setDetailId] = useState<string | null>(null);
  const [formTarget, setFormTarget] = useState<Project | "new" | null>(null);

  const filters: ProjectFilters = { search: debouncedSearch, status, partnerId };

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: projectKeys.list(filters),
    queryFn: () => fetchProjects(filters),
    // La liste PRÉCÉDENTE reste affichée pendant que la nouvelle arrive :
    // sans cela, chaque recherche renvoyait le tableau à ses squelettes,
    // et l'écran clignotait à chaque pause de frappe.
    placeholderData: keepPreviousData,
  });

  // Le filtre par client a besoin du répertoire des tiers. `fetchPartners`
  // demande déjà 100 lignes au serveur, largement au-delà de la page par
  // défaut : un déroulant qui ne montrerait que les 25 premiers clients
  // donnerait un filtre incapable d'atteindre le 26ᵉ.
  const partnerFilters = { type: "client" as const };
  const { data: partners } = useQuery({
    queryKey: partnerKeys.list(partnerFilters),
    queryFn: () => fetchPartners(partnerFilters),
  });

  const columns: readonly Column<Project>[] = [
    {
      id: "title",
      header: t("table.title"),
      cell: (row) => <span className="font-medium">{row.title}</span>,
    },
    {
      id: "client",
      header: t("table.client"),
      cell: (row) =>
        row.clientName ?? (
          // Le projet survit à l'archivage de son client : on le dit plutôt que
          // d'afficher une cellule vide, qui passerait pour une donnée manquante.
          <span className="text-muted-foreground">{t("table.archivedClient")}</span>
        ),
    },
    {
      id: "service",
      header: t("table.service"),
      hideBelow: "md",
      // `null` recouvre deux cas que la donnée reçue ne distingue pas : le
      // projet non classé — le régime par défaut — et le service archivé
      // depuis. Le tiret les dit tous les deux sans en inventer un troisième.
      cell: (row) =>
        row.serviceName ?? <span className="text-muted-foreground">—</span>,
    },
    {
      id: "status",
      header: t("table.status"),
      cell: (row) => <ProjectStatusBadge status={row.status} />,
    },
    {
      id: "progress",
      header: t("table.progress"),
      className: "w-48",
      cell: (row) => (
        <ProgressBar
          value={row.progressPercentage}
          label={t("detail.progressLabel", { title: row.title })}
        />
      ),
    },
    {
      id: "deliverables",
      header: t("table.deliverables"),
      align: "end",
      hideBelow: "md",
      cell: (row) => (
        <span className="amount">{row.deliverableCount ?? 0}</span>
      ),
    },
  ];

  // Les deux panneaux ne sont montés — donc téléchargés — qu'à leur
  // première ouverture.
  const detailMounted = useDeferredMount(detailId !== null);
  const formMounted = useDeferredMount(formTarget !== null);

  return (
    <div>
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <Button onClick={() => setFormTarget("new")}>
            <Plus className="size-4" aria-hidden />
            {t("actions.create")}
          </Button>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
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

        <Select value={partnerId} onValueChange={setPartnerId}>
          <SelectTrigger className="w-52" aria-label={t("table.client")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t("filters.allClients")}</SelectItem>
            {(partners?.data ?? []).map((partner) => (
              <SelectItem key={partner.id} value={partner.id}>
                {partner.legalName}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="w-40" aria-label={t("table.status")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t("filters.allStatuses")}</SelectItem>
            {PROJECT_STATUSES.map((value) => (
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
        onRowClick={(row) => setDetailId(row.id)}
        empty={{
          icon: FolderKanban,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button onClick={() => setFormTarget("new")}>
              <Plus className="size-4" aria-hidden />
              {t("actions.create")}
            </Button>
          ),
        }}
      />

      {detailMounted && (
        <ProjectDetailSheet
          projectId={detailId}
          onOpenChange={(open) => !open && setDetailId(null)}
          onEdit={(project) => {
            setDetailId(null);
            setFormTarget(project);
          }}
        />
      )}

      {formMounted && (
        <ProjectFormDialog
          target={formTarget}
          onOpenChange={(open) => !open && setFormTarget(null)}
        />
      )}
    </div>
  );
}
