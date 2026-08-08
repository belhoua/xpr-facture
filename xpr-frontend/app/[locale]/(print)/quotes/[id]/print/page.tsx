import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { QuotePrintView } from "@/features/documents/components/quote-print-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.quote") };
}

/**
 * Devis imprimable. Route dédiée et non modale : un devis se relit, se
 * partage par son URL et s'imprime au format A4 — trois choses qu'un panneau
 * latéral ne sait pas faire. Le panneau de détail y renvoie.
 */
export default async function PrintQuotePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <QuotePrintView id={id} />;
}
