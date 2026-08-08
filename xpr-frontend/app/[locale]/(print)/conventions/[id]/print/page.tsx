import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { ConventionPrintView } from "@/features/conventions/components/convention-print-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("conventions");

  return { title: t("title") };
}

/**
 * Contrat de convention imprimable. Route dédiée et non modale : un contrat se
 * relit, se partage par son URL et s'imprime au format A4 — trois choses qu'un
 * panneau latéral ne sait pas faire.
 */
export default async function PrintConventionPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <ConventionPrintView id={id} />;
}
