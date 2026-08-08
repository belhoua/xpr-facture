import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { DepositPrintView } from "@/features/conventions/components/deposit-print-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("deposits.print");

  return { title: t("title") };
}

/** Fiche de suivi d'un dépôt de dossier, aux couleurs de BCAT. */
export default async function PrintDepositPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  return <DepositPrintView id={id} />;
}
