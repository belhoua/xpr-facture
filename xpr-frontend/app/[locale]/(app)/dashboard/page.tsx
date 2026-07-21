import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { DashboardView } from "@/features/dashboard/components/dashboard-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("dashboard");

  return { title: t("title") };
}

/**
 * Accueil de l'espace client. La page reste un Server Component : seule la vue
 * qui consomme TanStack Query est marquée "use client".
 */
export default function DashboardPage() {
  return <DashboardView />;
}
