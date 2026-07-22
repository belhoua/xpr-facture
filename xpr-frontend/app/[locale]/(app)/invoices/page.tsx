import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { DocumentsView } from "@/features/documents/components/documents-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.invoice") };
}

/**
 * Factures. Le type est fixé par la ROUTE et non par un filtre : facture et
 * devis n'ont ni le même cycle de vie, ni les mêmes actions, ni la même
 * séquence de numérotation — les mêler dans une liste unique obligerait
 * l'utilisateur à filtrer avant chaque action.
 */
export default function InvoicesPage() {
  return <DocumentsView type="invoice" />;
}
