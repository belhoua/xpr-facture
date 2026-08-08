import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PageHeader } from "@/components/patterns/page-header";
import { SituationForm } from "@/features/situations/components/situation-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("situations");

  return { title: t("create.title") };
}

/**
 * Création d'une situation.
 *
 * Une page et non une modale, contrairement aux factures : l'écran a sa propre
 * URL (`/situations/create`), donc il survit à un rechargement et se partage
 * par lien — ce qu'une boîte de dialogue sans route ne permet pas.
 */
export default async function CreateSituationPage() {
  const t = await getTranslations("situations");

  return (
    <>
      <PageHeader title={t("create.title")} description={t("create.description")} />
      <SituationForm />
    </>
  );
}
