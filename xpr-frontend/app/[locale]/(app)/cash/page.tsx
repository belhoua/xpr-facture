import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { CashView } from "@/features/cash/components/cash-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("cash");

  return { title: t("title") };
}

export default function CashPage() {
  return <CashView />;
}
