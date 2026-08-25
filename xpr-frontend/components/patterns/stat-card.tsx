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
/**
 * Teinte de l'icône, quand la tuile porte un SENS et pas seulement un chiffre.
 *
 * Réservé aux grandeurs opposées présentées côte à côte — encaissements et
 * décaissements — où la couleur est ce qui distingue les deux d'un coup d'œil.
 * Une tuile isolée n'en a pas besoin : elle n'est en contraste avec rien.
 *
 * Les teintes viennent du jeu de statuts (`--status-paid`, `--status-overdue`),
 * pas d'une palette ajoutée pour l'occasion : le vert du « payé » et le rouge
 * du « en retard » disent déjà l'entrée et la sortie dans tout le produit, et
 * ils suivent le mode sombre (§11).
 *
 * Seule l'ICÔNE est colorée, jamais le montant. Trois chiffres dont deux
 * teintés se disputeraient l'attention dans un bandeau qu'on lit d'un balayage,
 * alors que la flèche, elle, ne porte que le sens.
 */
const TONE_COLOR = {
  positive: "text-status-paid",
  negative: "text-status-overdue",
} as const;

export function StatCard({
  label,
  value,
  icon: Icon,
  tone,
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
  tone?: keyof typeof TONE_COLOR;
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
        <Icon
          className={cn(
            "size-4 shrink-0",
            tone ? TONE_COLOR[tone] : "text-muted-foreground",
          )}
          aria-hidden
        />
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
