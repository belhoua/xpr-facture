"use client";

import { useQuery } from "@tanstack/react-query";
import {
  ArrowLeft,
  ClipboardList,
  Coins,
  Printer,
  Search,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

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
import { fetchPartner, partnerKeys } from "@/features/partners/api/partners";
import type { Document } from "@/features/documents/schemas/document";
import {
  fetchSituationSummary,
  fetchSituations,
  situationKeys,
  type SituationFilters,
} from "@/features/situations/api/situations";
import { useClientProjects } from "@/features/projects/hooks/use-client-projects";
import { SituationStatusBadge } from "@/features/situations/components/situation-status-badge";
import { SITUATION_STATUSES } from "@/features/situations/schemas/situation";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney, formatNumber } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/** Filtres proposés : le cycle de règlement, plus les situations annulées. */
const STATUS_FILTERS = [...SITUATION_STATUSES, "cancelled"] as const;

/**
 * Ce qui entre dans le solde d'un client : ses situations d'avancement ET ses
 * factures. Le devis en est exclu — il propose, il ne crée aucune créance, et
 * l'additionner gonflerait le « reste à payer » d'un montant que personne ne
 * doit.
 */
const CLIENT_DOCUMENT_TYPES = ["situation", "invoice"] as const;

/** Sentinelle « tous les projets » : Radix interdit la chaîne vide en valeur. */
const ALL_PROJECTS = "all";

/**
 * Encours d'UN client : quatre indicateurs, puis le détail.
 *
 * L'écran couvre ses SITUATIONS ET SES FACTURES. S'en tenir aux situations
 * donnerait un « reste à payer » qui n'est pas celui du client : ce qu'il doit
 * ne dépend pas du type de pièce par lequel on le lui a demandé.
 *
 * Les indicateurs viennent d'un endpoint d'agrégats (`/documents/summary`) et
 * NON d'une somme des lignes affichées : la table est paginée, additionner la
 * page donnerait un total faux dès la 26ᵉ pièce — et faux sans le dire. Les
 * deux requêtes partagent les mêmes filtres, pour que les chiffres du haut
 * décrivent exactement les lignes du bas.
 *
 * Le « PAYÉ » qu'ils affichent est celui des RÈGLEMENTS ENREGISTRÉS, que le
 * serveur lit dans `payments` ; la colonne « Règlements » en montre le détail
 * pièce par pièce, de sorte que l'indicateur soit vérifiable sur le même
 * écran plutôt que d'être un chiffre à croire.
 *
 * L'impression passe par `window.print()` sur la page elle-même plutôt que par
 * un PDF serveur : c'est un état des lieux consultable, pas une pièce à
 * archiver — Gotenberg reste réservé aux documents qui engagent (§4).
 */
export function ClientSituationsView({ clientId }: { clientId: string }) {
  const t = useTranslations("situations");
  const tCommon = useTranslations("common");
  const tPayments = useTranslations("payments");
  const locale = useLocale();

  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("all");
  const [projectId, setProjectId] = useState(ALL_PROJECTS);

  /** Chantiers de CE client — le déroulant n'en propose aucun autre. */
  const { projects } = useClientProjects(clientId);

  // Le filtre projet entre dans les MÊMES filtres que la liste : les deux
  // requêtes les partagent, et c'est ce qui fait que les quatre cartes
  // décrivent exactement les lignes affichées en dessous — y compris une fois
  // le chantier choisi.
  const filters: SituationFilters = {
    search,
    status,
    partnerId: clientId,
    projectId: projectId === ALL_PROJECTS ? "" : projectId,
    types: CLIENT_DOCUMENT_TYPES,
  };

  const { data: partner } = useQuery({
    queryKey: partnerKeys.detail(clientId),
    queryFn: () => fetchPartner(clientId),
  });

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: situationKeys.list(filters),
    queryFn: () => fetchSituations(filters),
  });

  const { data: summary, isPending: summaryPending } = useQuery({
    queryKey: situationKeys.summary(filters),
    queryFn: () => fetchSituationSummary(filters),
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
    },
    {
      id: "type",
      header: t("table.documentType"),
      // Ternaire et non une clé construite : l'écran ne demande que ces deux
      // types (CLIENT_DOCUMENT_TYPES), et `t(\`…${row.type}\`)` sortirait du
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
      id: "project",
      header: t("table.project"),
      hideBelow: "lg",
      // `projectTitle` retombe à null quand le chantier a été archivé depuis :
      // la pièce garde son rattachement, mais il n'y a plus de titre à
      // afficher. Le tiret le dit sans en inventer un.
      cell: (row) =>
        row.projectTitle ?? <span className="text-muted-foreground">—</span>,
    },
    {
      id: "subject",
      header: t("table.subject"),
      // Une facture n'a pas d'objet : son sens est dans ses lignes, que cet
      // écran n'affiche pas. Le nom du client figé la désigne alors.
      cell: (row) => row.subject ?? row.clientName,
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
        <span className="amount">{formatMoney(row.paidCents, locale, row.currency)}</span>
      ),
    },
    {
      id: "payments",
      header: t("table.settlements"),
      // Le détail est affiché À PLAT et non derrière un dépliant : cet écran
      // est fait pour être imprimé (window.print()), et ce qui se replie ne
      // s'imprime pas. Un tiret quand la pièce n'a aucun règlement enregistré,
      // ce qui est le régime normal d'une situation — son avance est saisie en
      // en-tête, elle n'a pas d'historique derrière elle.
      cell: (row) =>
        (row.payments ?? []).length === 0 ? (
          <span className="text-muted-foreground">—</span>
        ) : (
          <ul className="space-y-0.5 text-xs">
            {(row.payments ?? []).map((payment) => (
              <li key={payment.id} className="flex flex-wrap items-baseline gap-x-1.5">
                <span className="text-muted-foreground">
                  {formatDate(payment.paidOn, locale)}
                </span>
                <span className="text-muted-foreground">
                  {tPayments(`methods.${payment.method}`)}
                </span>
                <span className="amount font-medium">
                  {formatMoney(payment.amountCents, locale, payment.currency)}
                </span>
                {payment.checkNumber && (
                  <span className="text-muted-foreground">
                    n° {payment.checkNumber}
                  </span>
                )}
              </li>
            ))}
          </ul>
        ),
    },
    {
      id: "remaining",
      header: t("table.remaining"),
      align: "end",
      cell: (row) => (
        <span className="amount font-medium">
          {formatMoney(row.remainingCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "status",
      header: t("table.status"),
      cell: (row) => <SituationStatusBadge status={row.status} />,
    },
  ];

  return (
    // `print-document` déclenche la mise en page d'impression définie dans
    // globals.css : fond blanc, filtres masqués, tableau non tronqué.
    <div className="print-document">
      <PageHeader
        title={partner?.legalName ?? t("byClient.title")}
        description={t("client.description")}
        actions={
          <div className="flex items-center gap-2 print:hidden">
            <Button variant="outline" asChild>
              <Link href="/situations/by-client">
                <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
                {tCommon("cancel")}
              </Link>
            </Button>
            <Button onClick={() => window.print()}>
              <Printer className="size-4" aria-hidden />
              {t("actions.printAll")}
            </Button>
          </div>
        }
      />

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

        {/* Le client est déjà connu : le déroulant ne montre que SES
            chantiers, et le titre seul suffit à les distinguer. */}
        <Select value={projectId} onValueChange={setProjectId}>
          <SelectTrigger className="w-56" aria-label={t("filters.project")}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL_PROJECTS}>{t("filters.allProjects")}</SelectItem>
            {projects.map((project) => (
              <SelectItem key={project.id} value={project.id}>
                {project.title}
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
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        empty={{
          icon: ClipboardList,
          title: t("client.empty.title"),
          description: t("client.empty.description"),
        }}
      />
    </div>
  );
}
