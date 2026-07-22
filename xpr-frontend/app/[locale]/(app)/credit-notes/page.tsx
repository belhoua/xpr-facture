import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

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
 */
export default function CreditNotesPage() {
  return <DocumentsView type="credit_note" />;
}
