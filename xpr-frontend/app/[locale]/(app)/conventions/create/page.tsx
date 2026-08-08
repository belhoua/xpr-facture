import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { PageHeader } from "@/components/patterns/page-header";
import { ConventionForm } from "@/features/conventions/components/convention-form";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("conventions");

  return { title: t("create.title") };
}

/**
 * Rédaction d'une convention à partir de rien.
 *
 * Le chemin le plus fréquent n'est pas celui-ci mais le TRANSFERT depuis un
 * devis, qui arrive directement sur l'écran d'édition avec l'identité et les
 * honoraires déjà remplis. Cette page reste nécessaire pour les conventions qui
 * précèdent tout document commercial.
 */
export default async function CreateConventionPage() {
  const t = await getTranslations("conventions");

  return (
    <>
      <PageHeader title={t("create.title")} description={t("create.description")} />
      <ConventionForm />
    </>
  );
}
