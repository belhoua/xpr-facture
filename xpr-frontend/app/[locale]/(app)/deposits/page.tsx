import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { DepositsView } from "@/features/conventions/components/deposits-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("deposits");

  return { title: t("title") };
}

/** Suivi transverse des dépôts de dossier, tous projets confondus. */
export default function DepositsPage() {
  return <DepositsView />;
}
