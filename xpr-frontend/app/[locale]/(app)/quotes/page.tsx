import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { DocumentsView } from "@/features/documents/components/documents-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.quote") };
}

/**
 * Devis. Même écran que les factures, cycle de vie propre au type.
 *
 * `Suspense` : la vue lit `?document=` (ouverture directe d'une pièce après un
 * transfert), et Next exige cette frontière pour prérendre la page — sans elle,
 * la lecture des paramètres d'URL ferait échouer le build.
 */
export default function QuotesPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <DocumentsView type="quote" />
    </Suspense>
  );
}
