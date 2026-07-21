"use client";

import { useTranslations } from "next-intl";
import { useState } from "react";

import {
  CheckoutSteps,
  type CheckoutStep,
} from "@/features/billing/components/checkout-steps";
import { BillingPeriodToggle } from "@/features/billing/components/billing-period-toggle";
import { PricingCard } from "@/features/billing/components/pricing-card";
import { PLANS, type BillingPeriod, type Plan } from "@/features/billing/types/plan";
import { useRouter } from "@/lib/i18n/navigation";

/**
 * Étape « choix du pack ». Client Component car elle porte deux états : la
 * périodicité choisie et la navigation vers le paiement.
 *
 * Le pack retenu transite par la query string plutôt que par un store : l'URL
 * reste partageable et le retour arrière du navigateur fonctionne, ce qui est
 * exactement ce qu'on attend d'un tunnel de souscription.
 */
export function PlanPicker() {
  const t = useTranslations("pricing");
  const router = useRouter();
  const [period, setPeriod] = useState<BillingPeriod>("monthly");

  const stepLabels: Record<CheckoutStep, string> = {
    account: t("steps.account"),
    plan: t("steps.plan"),
    payment: t("steps.payment"),
    done: t("steps.done"),
  };

  function selectPlan(plan: Plan) {
    // Le pack gratuit ne passe pas par la caisse.
    if (plan.monthlyPriceCents === 0) {
      router.push("/subscribe/success");

      return;
    }

    router.push(`/subscribe/payment?plan=${plan.id}&period=${period}`);
  }

  return (
    <div>
      <CheckoutSteps current="plan" labels={stepLabels} />

      <div className="mt-10 text-center">
        <h1 className="font-heading text-2xl font-semibold tracking-tight sm:text-3xl">
          {t("title")}
        </h1>
        <p className="text-muted-foreground mx-auto mt-2 max-w-2xl text-sm text-balance">
          {t("description")}
        </p>
        <div className="mt-6 flex justify-center">
          <BillingPeriodToggle value={period} onChange={setPeriod} />
        </div>
      </div>

      <div className="mt-10 grid gap-5 md:grid-cols-3">
        {PLANS.map((plan) => (
          <PricingCard
            key={plan.id}
            plan={plan}
            period={period}
            onSelect={selectPlan}
          />
        ))}
      </div>

      <p className="text-muted-foreground mt-8 text-center text-xs">
        {t("vatNote")}
      </p>
    </div>
  );
}
