"use client";

import { useLocale, useTranslations } from "next-intl";
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from "recharts";

import type { DocumentStatus } from "@/components/patterns/status-badge";
import type { StatusBreakdown } from "@/features/dashboard/schemas/stats";
import { formatMoney, formatNumber } from "@/lib/format";

/**
 * Répartition des factures par statut, en anneau + légende chiffrée.
 *
 * L'anneau seul est un mauvais support de comparaison ; la légende à côté
 * porte le nombre ET le montant, qui sont ce qu'on vient réellement lire.
 * Les couleurs proviennent des tokens sémantiques : « payée » est vert ici
 * comme partout ailleurs dans l'application.
 */
const STATUS_VAR: Record<DocumentStatus, string> = {
  draft: "var(--color-status-draft)",
  sent: "var(--color-status-sent)",
  accepted: "var(--color-status-accepted)",
  refused: "var(--color-status-refused)",
  converted: "var(--color-status-converted)",
  partial: "var(--color-status-partial)",
  paid: "var(--color-status-paid)",
  overdue: "var(--color-status-overdue)",
  cancelled: "var(--color-status-cancelled)",
};

export function StatusBreakdownChart({
  data,
}: {
  data: readonly StatusBreakdown[];
}) {
  const t = useTranslations("dashboard.statusChart");
  const tStatus = useTranslations("status");
  const locale = useLocale();

  return (
    <div className="bg-card ring-border min-w-0 overflow-hidden rounded-lg p-4 ring-1">
      <div className="mb-4">
        <h2 className="font-heading text-sm font-medium">{t("title")}</h2>
        <p className="text-muted-foreground mt-0.5 text-xs">
          {t("description")}
        </p>
      </div>

      <div className="flex flex-col items-center gap-4 sm:flex-row">
        <ResponsiveContainer width="100%" height={180} className="max-w-44">
          <PieChart>
            <Pie
              data={[...data]}
              dataKey="count"
              nameKey="status"
              innerRadius={48}
              outerRadius={74}
              paddingAngle={2}
              strokeWidth={0}
            >
              {data.map((entry) => (
                <Cell key={entry.status} fill={STATUS_VAR[entry.status]} />
              ))}
            </Pie>
            <Tooltip
              contentStyle={{
                background: "var(--color-popover)",
                border: "1px solid var(--color-border)",
                borderRadius: "var(--radius-md)",
                fontSize: "0.8125rem",
              }}
              formatter={(value, name) => [
                typeof value === "number" ? formatNumber(value, locale) : "—",
                typeof name === "string" ? tStatus(name) : "",
              ]}
            />
          </PieChart>
        </ResponsiveContainer>

        <ul className="w-full flex-1 space-y-1.5">
          {data.map((entry) => (
            <li
              key={entry.status}
              className="flex items-center justify-between gap-3 text-sm"
            >
              <span className="flex min-w-0 items-center gap-2">
                <span
                  aria-hidden
                  className="size-2 shrink-0 rounded-full"
                  style={{ background: STATUS_VAR[entry.status] }}
                />
                <span className="text-muted-foreground truncate">
                  {tStatus(entry.status)}
                </span>
              </span>
              <span className="flex shrink-0 items-baseline gap-2">
                <span className="amount text-muted-foreground text-xs">
                  {formatNumber(entry.count, locale)}
                </span>
                <span className="amount font-medium">
                  {formatMoney(entry.totalCents, locale)}
                </span>
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
