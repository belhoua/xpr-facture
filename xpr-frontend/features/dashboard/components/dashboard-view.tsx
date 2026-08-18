"use client";

import {
  AlertCircle,
  BarChart3,
  Coins,
  Plus,
  TrendingUp,
  Users,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useState } from "react";

import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState } from "@/components/patterns/error-state";
import { PageHeader } from "@/components/patterns/page-header";
import { StatCard } from "@/components/patterns/stat-card";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { useDashboardStats } from "@/features/dashboard/hooks/use-dashboard-stats";
import { formatMoney, formatNumber, formatPercent } from "@/lib/format";
import { toApiProblem } from "@/lib/api/client";

/**
 * Les TROIS graphiques sont chargés à la demande, pas avec la page.
 *
 * Ils sont les seuls consommateurs de Recharts dans toute l'application, et
 * cette bibliothèque pèse à elle seule plus que le reste du tableau de bord.
 * Importée statiquement, elle partait dans le lot initial : le navigateur
 * téléchargeait, parsait et exécutait le moteur de graphiques AVANT de pouvoir
 * afficher la moindre carte d'indicateur — alors que les six KPI, eux, n'en
 * ont aucun besoin et sont ce que l'utilisateur lit en premier.
 *
 * `ssr: false` : `ResponsiveContainer` mesure son conteneur, il ne rend rien
 * d'exploitable côté serveur. Le rendre y coûterait du HTML pour un cadre vide.
 *
 * `loading` reprend EXACTEMENT le squelette déjà affiché pendant la requête
 * de statistiques : la transition « chargement → graphique » est la même,
 * qu'elle attende les données ou le code.
 */
const RevenueChart = dynamic(
  () =>
    import("@/features/dashboard/components/revenue-chart").then(
      (m) => m.RevenueChart,
    ),
  { ssr: false, loading: () => <Skeleton className="h-[340px] rounded-lg" /> },
);

const StatusBreakdownChart = dynamic(
  () =>
    import("@/features/dashboard/components/status-breakdown-chart").then(
      (m) => m.StatusBreakdownChart,
    ),
  { ssr: false, loading: () => <Skeleton className="h-[340px] rounded-lg" /> },
);

const TopClientsChart = dynamic(
  () =>
    import("@/features/dashboard/components/top-clients-chart").then(
      (m) => m.TopClientsChart,
    ),
  { ssr: false, loading: () => <Skeleton className="h-[360px] rounded-lg" /> },
);

const PERIODS = ["last7", "last30", "last90", "year"] as const;

/**
 * Vue d'ensemble de l'activité. Elle orchestre les QUATRE états imposés par §6
 * — chargement (squelettes), erreur, vide, succès — sans jamais fabriquer de
 * chiffre : tant que `GET /dashboard/stats` n'existe pas côté Laravel, c'est
 * l'état d'erreur qui s'affiche, avec le `detail` du Problem Details.
 */
export function DashboardView() {
  const t = useTranslations("dashboard");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const [period, setPeriod] = useState<string>("last30");

  const { data, isPending, isError, error, refetch } =
    useDashboardStats(period);

  const periodSelect = (
    <Select value={period} onValueChange={setPeriod}>
      <SelectTrigger size="sm" className="w-44">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {PERIODS.map((value) => (
          <SelectItem key={value} value={value}>
            {tCommon(`period.${value}`)}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );

  const header = (
    <PageHeader
      title={t("title")}
      description={t("description")}
      actions={
        <>
          {periodSelect}
          <Button size="sm">
            <Plus aria-hidden />
            {t("newInvoice")}
          </Button>
        </>
      }
    />
  );

  if (isError) {
    return (
      <>
        {header}
        <div className="bg-card ring-border rounded-lg ring-1">
          <ErrorState
            detail={toApiProblem(error).detail}
            onRetry={() => void refetch()}
          />
        </div>
      </>
    );
  }

  // État vide : l'API répond, mais la société n'a encore rien facturé.
  if (data && data.revenueCents === 0 && data.statusBreakdown.length === 0) {
    return (
      <>
        {header}
        <div className="bg-card ring-border rounded-lg ring-1">
          <EmptyState
            icon={BarChart3}
            title={t("empty.title")}
            description={t("empty.description")}
            action={
              <Button size="sm">
                <Plus aria-hidden />
                {t("newInvoice")}
              </Button>
            }
          />
        </div>
      </>
    );
  }

  return (
    <>
      {header}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <StatCard
          label={t("kpi.revenue")}
          icon={TrendingUp}
          loading={isPending}
          value={data ? formatMoney(data.revenueCents, locale) : "—"}
          trend={data ? formatPercent(data.revenueTrend, locale) : undefined}
          trendDirection={
            data
              ? data.revenueTrend > 0
                ? "up"
                : data.revenueTrend < 0
                  ? "down"
                  : "flat"
              : undefined
          }
          hint={t("kpi.revenueHint")}
        />
        <StatCard
          label={t("kpi.paid")}
          icon={Wallet}
          loading={isPending}
          value={data ? formatMoney(data.collectedCents, locale) : "—"}
        />
        <StatCard
          label={t("kpi.outstanding")}
          icon={BarChart3}
          loading={isPending}
          value={data ? formatMoney(data.outstandingCents, locale) : "—"}
        />
        <StatCard
          label={t("kpi.overdue")}
          icon={AlertCircle}
          loading={isPending}
          value={data ? formatMoney(data.overdueCents, locale) : "—"}
          hint={
            data ? t("kpi.overdueHint", { count: data.overdueCount }) : undefined
          }
          // Sur « en retard », une hausse est une mauvaise nouvelle.
          invertTrendColor
        />
        <StatCard
          label={t("kpi.activeClients")}
          icon={Users}
          loading={isPending}
          // Un COMPTE, pas un montant : formatNumber, sans symbole monétaire.
          value={data ? formatNumber(data.activeClients, locale) : "—"}
          hint={
            data
              ? t("kpi.activeClientsHint", { count: data.activeSuppliers })
              : undefined
          }
        />
        <StatCard
          label={t("kpi.cashBalance")}
          icon={Coins}
          loading={isPending}
          value={data ? formatMoney(data.cashBalanceCents, locale) : "—"}
          hint={
            data
              ? t("kpi.cashBalanceHint", {
                  inflow: formatMoney(data.cashInflowCents, locale),
                  outflow: formatMoney(data.cashOutflowCents, locale),
                })
              : undefined
          }
        />
      </div>

      <div className="mt-3 grid gap-3 lg:grid-cols-[1.6fr_1fr]">
        {isPending || !data ? (
          <>
            <Skeleton className="h-[340px] rounded-lg" />
            <Skeleton className="h-[340px] rounded-lg" />
          </>
        ) : (
          <>
            <RevenueChart data={data.revenueSeries} />
            <StatusBreakdownChart data={data.statusBreakdown} />
          </>
        )}
      </div>

      <div className="mt-3">
        {isPending || !data ? (
          <Skeleton className="h-[360px] rounded-lg" />
        ) : (
          <TopClientsChart data={data.topClients} />
        )}
      </div>
    </>
  );
}
