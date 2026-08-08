import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { SituationsView } from "@/features/situations/components/situations-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("title") };
}

/** Liste de toutes les situations de la société active. */
export default function SituationsPage() {
  return <SituationsView />;
}
