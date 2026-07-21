import { ArrowDownRight, ArrowUpRight, type LucideIcon } from "lucide-react";

import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

/**
 * Tuile de KPI du dashboard. Elle porte son propre état de chargement
 * (`loading`) pour que la page n'ait pas à dupliquer un squelette par tuile.
 *
 * `trend` est la variation par rapport à la période précédente, DÉJÀ formatée
 * et déjà localisée. `trendDirection` est séparé de la valeur car une baisse
 * n'est pas toujours une mauvaise nouvelle : sur « factures en retard », un
 * `-15 %` est vert. C'est l'appelant qui tranche via `invertTrendColor`.
 */
export function StatCard({
  label,
  value,
  icon: Icon,
  trend,
  trendDirection,
  invertTrendColor = false,
  hint,
  loading = false,
  className,
}: {
  label: string;
  value: string;
  icon: LucideIcon;
  trend?: string;
  trendDirection?: "up" | "down" | "flat";
  invertTrendColor?: boolean;
  hint?: string;
  loading?: boolean;
  className?: string;
}) {
  const isPositive = invertTrendColor
    ? trendDirection === "down"
    : trendDirection === "up";

  return (
    <div
      className={cn(
        "bg-card ring-border relative overflow-hidden rounded-lg p-4 ring-1 transition-colors",
        "hover:ring-primary/25",
        className,
      )}
    >
      <div className="flex items-center justify-between gap-2">
        <span className="text-muted-foreground truncate text-xs font-medium tracking-wide uppercase">
          {label}
        </span>
        <Icon className="text-muted-foreground size-4 shrink-0" aria-hidden />
      </div>

      {loading ? (
        <Skeleton className="mt-3 h-8 w-28" />
      ) : (
        <p className="amount mt-3 text-2xl leading-none font-semibold tracking-tight">
          {value}
        </p>
      )}

      <div className="mt-2 flex min-h-5 items-center gap-1.5 text-xs">
        {loading ? (
          <Skeleton className="h-4 w-20" />
        ) : trend && trendDirection && trendDirection !== "flat" ? (
          <>
            <span
              className={cn(
                "inline-flex items-center gap-0.5 font-medium",
                isPositive ? "text-status-paid" : "text-status-overdue",
              )}
            >
              {trendDirection === "up" ? (
                <ArrowUpRight className="size-3.5" aria-hidden />
              ) : (
                <ArrowDownRight className="size-3.5" aria-hidden />
              )}
              <span className="amount">{trend}</span>
            </span>
            {hint ? (
              <span className="text-muted-foreground truncate">{hint}</span>
            ) : null}
          </>
        ) : hint ? (
          <span className="text-muted-foreground truncate">{hint}</span>
        ) : null}
      </div>
    </div>
  );
}
