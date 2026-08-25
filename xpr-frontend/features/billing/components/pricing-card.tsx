"use client";

import { Check } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  chargedCents,
  displayedMonthlyCents,
  type BillingPeriod,
  type Plan,
} from "@/features/billing/types/plan";
import { formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

/**
 * Carte d'un pack d'abonnement.
 *
 * Parti pris visuel (CLAUDE.md §11) : pas d'ombre portée ni de dégradé. La
 * carte mise en avant se distingue par un anneau d'accent 2px et un badge —
 * la hiérarchie passe par la couleur et le poids, jamais par l'élévation.
 *
 * Les libellés de fonctionnalités sont indexés (`features.0`, `features.1`…)
 * dans les dictionnaires : `featureCount` borne la boucle, ce qui évite un
 * `t.raw()` non typé.
 */
export function PricingCard({
  plan,
  period,
  onSelect,
}: {
  plan: Plan;
  period: BillingPeriod;
  onSelect: (plan: Plan) => void;
}) {
  const t = useTranslations("pricing");
  const locale = useLocale();

  const monthly = displayedMonthlyCents(plan, period);
  const charged = chargedCents(plan, period);
  const planName = t(`plans.${plan.id}.name`);

  return (
    <div
      className={cn(
        "bg-card relative flex flex-col rounded-xl p-6 transition-colors",
        plan.highlighted
          ? "ring-primary ring-2"
          : "ring-border hover:ring-primary/30 ring-1",
      )}
    >
      {plan.highlighted && (
        <span className="bg-primary text-primary-foreground absolute -top-2.5 start-6 rounded-full px-2.5 py-0.5 text-[0.6875rem] font-semibold tracking-wide uppercase">
          {t("mostPopular")}
        </span>
      )}

      <div>
        <h3 className="font-heading text-base font-semibold">{planName}</h3>
        <p className="text-muted-foreground mt-1 text-sm">
          {t(`plans.${plan.id}.tagline`)}
        </p>
      </div>

      {/* Plus de branche « gratuit » : le pack a été retiré du catalogue le
          2026-08-26 (cf. `PLAN_IDS`), et tous les packs commercialisés portent
          un prix. La garder aurait laissé un chemin que plus aucune donnée
          n'emprunte. */}
      <div className="mt-5">
        <div className="flex items-baseline gap-1.5">
          <span className="amount text-3xl font-semibold tracking-tight">
            {formatMoney(monthly, locale)}
          </span>
          <span className="text-muted-foreground text-sm">{t("perMonth")}</span>
        </div>
        {period === "yearly" && (
          <p className="text-muted-foreground mt-1 text-xs">
            {t("billedYearly", { amount: formatMoney(charged, locale) })}
          </p>
        )}
      </div>

      <Button
        size="lg"
        variant={plan.highlighted ? "default" : "outline"}
        className="mt-5 w-full"
        onClick={() => onSelect(plan)}
      >
        {t("choose", { plan: planName })}
      </Button>

      <ul className="mt-6 space-y-2.5 text-sm">
        {Array.from({ length: plan.featureCount }, (_, index) => (
          <li key={index} className="flex items-start gap-2.5">
            <Check
              className="text-status-paid mt-0.5 size-4 shrink-0"
              aria-hidden
            />
            <span className="text-muted-foreground">
              {t(`plans.${plan.id}.features.${index}`)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
