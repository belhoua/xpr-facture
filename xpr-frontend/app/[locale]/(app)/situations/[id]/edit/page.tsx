import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PageHeader } from "@/components/patterns/page-header";
import { SituationEditor } from "@/features/situations/components/situation-editor";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("edit.title") };
}

/**
 * Correction d'une situation, y compris NUMÉROTÉE — c'est la seule exception à
 * l'immuabilité du §3, portée côté serveur par `DocumentType::freezesOnIssue()`.
 */
export default async function EditSituationPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const [{ id }, t] = await Promise.all([params, getTranslations("situations")]);

  return (
    <>
      <PageHeader title={t("edit.title")} description={t("edit.description")} />
      <SituationEditor id={id} />
    </>
  );
}
