import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { ServicesView } from "@/features/services/components/services-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("services");

  return { title: t("title") };
}

export default function ServicesPage() {
  return <ServicesView />;
}
