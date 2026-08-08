import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { InvoicePrintView } from "@/features/documents/components/invoice-print-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.invoice") };
}

/**
 * Facture imprimable. Route dédiée et non modale, pour les mêmes raisons que le
 * devis : une facture se relit, se partage par son URL et s'imprime au format
 * A4 — trois choses qu'un panneau latéral ne sait pas faire. La liste et le
 * panneau de détail y renvoient tous deux.
 */
export default async function PrintInvoicePage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <InvoicePrintView id={id} />;
}
