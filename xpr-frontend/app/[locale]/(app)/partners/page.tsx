import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PartnersView } from "@/features/partners/components/partners-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("partners");

  return { title: t("title") };
}

export default function PartnersPage() {
  return <PartnersView />;
}
