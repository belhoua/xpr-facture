import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

/**
 * Point d'atterrissage après connexion/inscription. Volontairement minimal :
 * le vrai tableau de bord est un module de Phase 1 ; la garde d'auth et
 * l'AppShell (sidebar, ⌘K) arrivent avec P0-16.
 */
export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("dashboard");

  return { title: t("title") };
}

export default async function DashboardPage() {
  const t = await getTranslations("dashboard");

  return (
    <main className="mx-auto max-w-2xl p-8">
      <h1 className="text-xl font-semibold">{t("title")}</h1>
      <p className="text-muted-foreground mt-2">{t("placeholder")}</p>
    </main>
  );
}
