import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PageHeader } from "@/components/patterns/page-header";
import { ConventionEditor } from "@/features/conventions/components/convention-editor";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("conventions");

  return { title: t("edit.title") };
}

/** Correction d'une convention et suivi de son dépôt de dossier. */
export default async function EditConventionPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const t = await getTranslations("conventions");

  return (
    <>
      <PageHeader title={t("edit.title")} description={t("edit.description")} />
      <ConventionEditor id={id} />
    </>
  );
}
