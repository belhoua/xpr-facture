import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { notFound } from "next/navigation";

import { PaymentForm } from "@/features/billing/components/payment-form";
import { findPlan, type BillingPeriod } from "@/features/billing/types/plan";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("pricing");

  return { title: t("payment.title") };
}

/**
 * Étape 3 : paiement (simulé). Le pack et la périodicité viennent de l'URL et
 * sont VALIDÉS ici — une query string est une entrée utilisateur, un `?plan=xyz`
 * inventé doit tomber en 404 plutôt que rendre un écran à moitié vide.
 */
export default async function PaymentPage({
  searchParams,
}: {
  searchParams: Promise<{ plan?: string; period?: string }>;
}) {
  const { plan: planId, period } = await searchParams;
  const plan = planId ? findPlan(planId) : undefined;

  if (!plan || plan.monthlyPriceCents === 0) {
    notFound();
  }

  const billingPeriod: BillingPeriod = period === "yearly" ? "yearly" : "monthly";

  return <PaymentForm plan={plan} period={billingPeriod} />;
}
