import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { ProjectsView } from "@/features/projects/components/projects-view";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("projects");

  return { title: t("title") };
}

/** Avancement de projet : la liste filtrable et son tiroir de détail. */
export default function ProjectsPage() {
  return <ProjectsView />;
}
