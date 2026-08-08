import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { ConventionsView } from "@/features/conventions/components/conventions-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("conventions");

  return { title: t("title") };
}

/** Liste des contrats de convention de contrôle et suivi. */
export default function ConventionsPage() {
  return <ConventionsView />;
}
