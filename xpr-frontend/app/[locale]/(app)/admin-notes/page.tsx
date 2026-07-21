import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { AdminNotesView } from "@/features/admin-notes/components/admin-notes-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("adminNotes");

  return { title: t("title") };
}

export default function AdminNotesPage() {
  return <AdminNotesView />;
}
