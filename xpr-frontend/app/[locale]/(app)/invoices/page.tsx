import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { InvoicesView } from "@/features/invoices/components/invoices-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("invoices");

  return { title: t("title") };
}

export default function InvoicesPage() {
  return <InvoicesView />;
}
