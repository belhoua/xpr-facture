import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { SituationPrintView } from "@/features/situations/components/situation-print-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("print.heading") };
}

/** Vue imprimable d'une situation : consultation à l'écran, ⌘P pour le papier. */
export default async function PrintSituationPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <SituationPrintView id={id} />;
}
