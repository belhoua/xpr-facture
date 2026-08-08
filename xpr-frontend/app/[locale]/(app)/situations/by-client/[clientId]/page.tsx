import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { ClientSituationsView } from "@/features/situations/components/client-situations-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("client.title") };
}

/**
 * Situations d'un client : quatre indicateurs, le détail filtrable, et
 * l'impression de l'ensemble.
 */
export default async function ClientSituationsPage({
  params,
}: {
  params: Promise<{ clientId: string }>;
}) {
  const { clientId } = await params;

  return <ClientSituationsView clientId={clientId} />;
}
