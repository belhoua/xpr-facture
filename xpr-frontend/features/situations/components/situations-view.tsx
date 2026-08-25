"use client";

import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  ClipboardList,
  Coins,
  Pencil,
  Plus,
  Printer,
  Search,
  Trash2,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { StatCard } from "@/components/patterns/stat-card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { printRoute, type Document } from "@/features/documents/schemas/document";
import {
  deleteSituation,
  fetchSituationSummary,
  fetchSituations,
  situationKeys,
  type SituationFilters,
} from "@/features/situations/api/situations";
import { useClientProjects } from "@/features/projects/hooks/use-client-projects";
import { SituationStatusBadge } from "@/features/situations/components/situation-status-badge";
import {
  SETTLEMENT_DOCUMENT_TYPES,
  SITUATION_STATUSES,
} from "@/features/situations/schemas/situation";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney, formatNumber } from "@/lib/format";
import { Link, useRouter } from "@/lib/i18n/navigation";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/** Filtres proposés : le cycle de règlement, plus les situations annulées. */
/** Sentinelle « tous les projets » : Radix interdit la chaîne vide en valeur. */
const ALL_PROJECTS = "all";

const STATUS_FILTERS = [...SITUATION_STATUSES, "cancelled"] as const;

/**
 * Suivi des encours : situations d'avancement ET factures.
 *
 * L'écran ne montrait que les situations jusqu'au 2026-08-24. Une facture née
 * d'un devis transféré n'y apparaissait donc jamais — et sur un dossier qui ne
 * travaille qu'au devis, la page restait vide alors que des créances existaient.
 * Le périmètre est désormais celui de l'écran par client
 * (`SETTLEMENT_DOCUMENT_TYPES`) : les deux répondent à la même question, ils
 * doivent porter sur les mêmes pièces.
 *
 * Ce que ce mélange impose : les ACTIONS se lisent sur le type de la ligne. Une
 * situation se corrige et se supprime depuis ici ; une facture, non — elle est
 * gelée par son numéro (§3), et son écran est `/invoices`. Toutes deux
 * s'impriment, chacune par son gabarit, que `printRoute` fait correspondre.
 *
 * Les quatre indicateurs viennent d'un endpoint d'agrégats
 * (`/documents/summary`) et NON d'une somme des lignes affichées : la table est
 * paginée, additionner la page donnerait un total faux dès la 26ᵉ pièce — et
 * faux sans le dire. Les deux requêtes partagent les mêmes filtres, pour que les
 * chiffres du haut décrivent exactement les lignes du bas.
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

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);
  const [status, setStatus] = useState("all");
  const [projectId, setProjectId] = useState(ALL_PROJECTS);
  // `"all"` demande TOUT le répertoire de projets : cet écran ne présuppose
  // aucun client, contrairement au formulaire de saisie.
  const { projects } = useClientProjects(ALL_PROJECTS);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [deleteTarget, setDeleteTarget] = useState<Document | null>(null);

  const filters: SituationFilters = {
    search: debouncedSearch,
    status,
    // « tous » est un état de l'interface, pas un filtre serveur.
    projectId: projectId === ALL_PROJECTS ? "" : projectId,
    from,
    to,
    types: SETTLEMENT_DOCUMENT_TYPES,
  };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: situationKeys.list(filters),
    queryFn: () => fetchSituations(filters),
    // La liste PRÉCÉDENTE reste affichée pendant que la nouvelle arrive :
    // sans cela, chaque recherche renvoyait le tableau à ses squelettes,
    // et l'écran clignotait à chaque pause de frappe.
    placeholderData: keepPreviousData,
  });

  // MÊMES filtres que la liste : c'est ce qui garantit que les quatre cartes
  // décrivent exactement les lignes affichées en dessous, filtre de chantier
  // et bornes de dates compris.
  const { data: summary, isPending: summaryPending } = useQuery({
    queryKey: situationKeys.summary(filters),
    queryFn: () => fetchSituationSummary(filters),
    placeholderData: keepPreviousData,
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
      id: "type",
      header: t("table.documentType"),
      // Ternaire et non une clé construite : l'écran ne demande que ces deux
      // types (SETTLEMENT_DOCUMENT_TYPES), et `t(`…${row.type}`)` sortirait du
      // typage des messages pour couvrir sept types qui n'y arriveront jamais.
      cell: (row) => (
        <span className="text-muted-foreground text-xs">
          {row.type === "invoice"
            ? t("table.documentTypes.invoice")
            : t("table.documentTypes.situation")}
        </span>
      ),
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
      id: "remaining",
      header: t("table.remaining"),
      align: "end",
      // Le solde vient du SERVEUR et n'est pas soustrait ici : sur une facture
      // il tient compte des règlements enregistrés, que cette ligne n'affiche
      // pas. Le recalculer depuis `total - paid` donnerait le même chiffre
      // aujourd'hui et un chiffre faux le jour où les deux divergent.
      cell: (row) => (
        <span className="amount font-medium">
          {formatMoney(row.remainingCents, locale, row.currency)}
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
      cell: (row) => {
        // Une FACTURE ne se corrige ni ne se supprime depuis cet écran : elle
        // est gelée par son numéro (§3), sa correction passe par un avoir, et
        // son cycle de vie vit sur `/invoices`. Lui offrir un crayon ici
        // mènerait à un formulaire de situation qui refuserait son type.
        const editable = row.type === "situation";
        const print = printRoute(row);

        return (
          // `stopPropagation` : la ligne entière est cliquable, les actions ne
          // doivent pas déclencher les deux.
          <div
            className="flex items-center justify-end gap-1"
            onClick={(event) => event.stopPropagation()}
          >
            {editable ? (
              <Button
                variant="ghost"
                size="icon"
                aria-label={tCommon("edit")}
                onClick={() => router.push(`/situations/${row.id}/edit`)}
              >
                <Pencil className="size-4" aria-hidden />
              </Button>
            ) : null}
            {/* `printRoute` fait correspondre le type à SON gabarit : la
                facture s'imprime en facture, pas dans la feuille de suivi
                d'une situation. */}
            {print !== null ? (
              <Button
                variant="ghost"
                size="icon"
                aria-label={t("actions.print")}
                asChild
              >
                <Link href={print}>
                  <Printer className="size-4" aria-hidden />
                </Link>
              </Button>
            ) : null}
            {editable ? (
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
          <Button asChild>
            <Link href="/situations/create">
              <Plus className="size-4" aria-hidden />
              {t("actions.create")}
            </Link>
          </Button>
        }
      />

      {/* Les quatre indicateurs de l'encours, mêmes filtres que la liste.
          Identiques à ceux de l'écran par client, et pour cause : c'est la même
          question posée sur tout le portefeuille au lieu d'un seul tiers. */}
      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label={t("kpi.count")}
          value={formatNumber(summary?.count ?? 0, locale)}
          icon={ClipboardList}
          loading={summaryPending}
        />
        <StatCard
          label={t("kpi.total")}
          value={formatMoney(summary?.totalCents ?? 0, locale)}
          icon={Coins}
          loading={summaryPending}
        />
        <StatCard
          label={t("kpi.paid")}
          value={formatMoney(summary?.paidCents ?? 0, locale)}
          icon={Wallet}
          loading={summaryPending}
        />
        <StatCard
          label={t("kpi.remaining")}
          value={formatMoney(summary?.remainingCents ?? 0, locale)}
          icon={Coins}
          loading={summaryPending}
        />
      </div>

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
        // La ligne mène là où la pièce se travaille : le formulaire pour une
        // situation, la facture imprimable pour une facture — seule vue d'une
        // facture qui ait sa propre URL. Envoyer les deux vers l'édition
        // afficherait « ce document n'est pas une situation » sur un clic
        // parfaitement légitime.
        onRowClick={(row) =>
          router.push(
            row.type === "situation"
              ? `/situations/${row.id}/edit`
              : (printRoute(row) ?? "/invoices"),
          )
        }
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
