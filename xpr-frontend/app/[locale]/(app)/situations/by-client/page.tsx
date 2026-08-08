import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { SituationsByClientView } from "@/features/situations/components/situations-by-client-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("byClient.title") };
}

/** Choix du client, avant de consulter ses situations et ses totaux. */
export default function SituationsByClientPage() {
  return <SituationsByClientView />;
}
