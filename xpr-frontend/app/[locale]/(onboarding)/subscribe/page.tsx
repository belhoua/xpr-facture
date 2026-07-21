import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PlanPicker } from "@/features/billing/components/plan-picker";

/**
 * Étape 2 du parcours d'inscription : choix du pack tarifaire en MAD.
 * La page reste un Server Component ; seul le sélecteur est client.
 */
export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("pricing");

  return { title: t("title") };
}

export default function SubscribePage() {
  return <PlanPicker />;
}
