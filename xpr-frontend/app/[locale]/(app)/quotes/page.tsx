import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { DocumentsView } from "@/features/documents/components/documents-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.quote") };
}

/** Devis. Même écran que les factures, cycle de vie propre au type. */
export default function QuotesPage() {
  return <DocumentsView type="quote" />;
}
