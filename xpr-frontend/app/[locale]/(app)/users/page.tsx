import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { UsersView } from "@/features/users/components/users-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("users");

  return { title: t("title") };
}

export default function UsersPage() {
  return <UsersView />;
}
