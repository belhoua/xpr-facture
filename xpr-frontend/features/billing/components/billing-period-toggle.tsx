"use client";

import { useTranslations } from "next-intl";

import type { BillingPeriod } from "@/features/billing/types/plan";
import { cn } from "@/lib/utils";

/**
 * Bascule mensuel / annuel. Implémentée en `radiogroup` et non en deux boutons :
 * c'est bien un choix exclusif, et les flèches du clavier doivent y naviguer.
 */
export function BillingPeriodToggle({
  value,
  onChange,
}: {
  value: BillingPeriod;
  onChange: (period: BillingPeriod) => void;
}) {
  const t = useTranslations("pricing");
  const periods: readonly BillingPeriod[] = ["monthly", "yearly"];

  return (
    <div
      role="radiogroup"
      aria-label={t("monthly")}
      className="bg-muted ring-border inline-flex items-center gap-1 rounded-lg p-1 ring-1"
    >
      {periods.map((period) => {
        const selected = value === period;

        return (
          <button
            key={period}
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={() => onChange(period)}
            className={cn(
              "focus-visible:ring-ring relative rounded-md px-3 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none",
              selected
                ? "bg-background text-foreground shadow-sm"
                : "text-muted-foreground hover:text-foreground",
            )}
          >
            {t(period)}
            {period === "yearly" && (
              <span className="bg-status-paid/10 text-status-paid ms-1.5 rounded px-1.5 py-0.5 text-[0.6875rem] font-medium">
                {t("yearlyHint")}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
