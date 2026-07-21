"use client";

import { useLocale, useTranslations } from "next-intl";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";

import type { RevenuePoint } from "@/features/dashboard/schemas/stats";
import { formatMoney, formatMoneyCompact } from "@/lib/format";
import { isRtl } from "@/lib/i18n/routing";

/**
 * Évolution du chiffre d'affaires : facturé vs encaissé.
 *
 * Deux séries et deux seulement : l'écart entre les courbes EST l'information
 * utile (ce qui est facturé mais pas rentré). Un troisième jeu de données
 * noierait ce message.
 *
 * En arabe, l'axe des mois est inversé (`reversed`) pour que le temps s'écoule
 * de droite à gauche, comme la lecture.
 */
export function RevenueChart({ data }: { data: readonly RevenuePoint[] }) {
  const t = useTranslations("dashboard.revenueChart");
  const locale = useLocale();
  const rtl = isRtl(locale);

  const monthLabel = (month: string): string => {
    const [year, monthIndex] = month.split("-");

    if (!year || !monthIndex) return month;

    return new Intl.DateTimeFormat(locale, { month: "short" }).format(
      new Date(Number(year), Number(monthIndex) - 1, 1),
    );
  };

  return (
    <div className="bg-card ring-border min-w-0 overflow-hidden rounded-lg p-4 ring-1">
      <div className="mb-4">
        <h2 className="font-heading text-sm font-medium">{t("title")}</h2>
        <p className="text-muted-foreground mt-0.5 text-xs">
          {t("description")}
        </p>
      </div>

      <ResponsiveContainer width="100%" height={260}>
        <AreaChart
          data={[...data]}
          margin={{ top: 4, right: 4, bottom: 0, left: 4 }}
        >
          <defs>
            {/* Aplat dégradé très léger sous la courbe : il donne du poids à la
                série sans introduire la « bouillie violette » proscrite par §11. */}
            <linearGradient id="invoicedFill" x1="0" y1="0" x2="0" y2="1">
              <stop
                offset="0%"
                stopColor="var(--color-chart-1)"
                stopOpacity={0.22}
              />
              <stop
                offset="100%"
                stopColor="var(--color-chart-1)"
                stopOpacity={0}
              />
            </linearGradient>
          </defs>

          <CartesianGrid
            vertical={false}
            stroke="var(--color-border)"
            strokeDasharray="3 3"
          />
          <XAxis
            dataKey="month"
            reversed={rtl}
            tickFormatter={monthLabel}
            tickLine={false}
            axisLine={false}
            tick={{ fontSize: 11, fill: "var(--color-muted-foreground)" }}
          />
          <YAxis
            orientation={rtl ? "right" : "left"}
            tickFormatter={(value: number) => formatMoneyCompact(value, locale)}
            tickLine={false}
            axisLine={false}
            width={64}
            tick={{ fontSize: 11, fill: "var(--color-muted-foreground)" }}
          />
          <Tooltip
            cursor={{ stroke: "var(--color-border)" }}
            contentStyle={{
              background: "var(--color-popover)",
              border: "1px solid var(--color-border)",
              borderRadius: "var(--radius-md)",
              fontSize: "0.8125rem",
            }}
            // Recharts type ses formatteurs très largement (ValueType | undefined) :
            // on narrow ici plutôt que de caster, sinon une valeur absente
            // afficherait « NaN MAD ».
            labelFormatter={(label) =>
              typeof label === "string" ? monthLabel(label) : ""
            }
            formatter={(value) =>
              typeof value === "number" ? formatMoney(value, locale) : "—"
            }
          />

          <Area
            type="monotone"
            dataKey="invoicedCents"
            name={t("title")}
            stroke="var(--color-chart-1)"
            strokeWidth={2}
            fill="url(#invoicedFill)"
          />
          <Area
            type="monotone"
            dataKey="collectedCents"
            name={t("description")}
            stroke="var(--color-status-paid)"
            strokeWidth={2}
            strokeDasharray="4 3"
            fill="none"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}
