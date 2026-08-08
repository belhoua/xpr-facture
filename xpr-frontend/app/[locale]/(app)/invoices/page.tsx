import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";

import { Skeleton } from "@/components/ui/skeleton";
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
 *
 * `Suspense` : la vue lit `?document=` — c'est par là qu'arrive la facture
 * issue d'un devis transféré, panneau de détail déjà ouvert.
 */
export default function InvoicesPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <DocumentsView type="invoice" />
    </Suspense>
  );
}
