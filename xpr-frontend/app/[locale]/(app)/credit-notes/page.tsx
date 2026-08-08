import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";
import { Suspense } from "react";

import { Skeleton } from "@/components/ui/skeleton";
import { DocumentsView } from "@/features/documents/components/documents-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("documents");

  return { title: t("title.credit_note") };
}

/**
 * Avoirs. Ils naissent presque toujours depuis une facture (§3 : corriger une
 * facture émise, c'est lui rattacher un avoir), mais ils ont besoin de leur
 * propre liste — sans quoi un avoir créé depuis une facture deviendrait
 * introuvable une fois le panneau refermé.
 *
 * `Suspense` : la vue lit `?document=` — c'est par là qu'arrive l'avoir issu
 * d'une facture transférée, panneau de détail déjà ouvert.
 */
export default function CreditNotesPage() {
  return (
    <Suspense fallback={<Skeleton className="h-96 w-full" />}>
      <DocumentsView type="credit_note" />
    </Suspense>
  );
}
