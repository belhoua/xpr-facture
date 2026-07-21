import { getTranslations } from "next-intl/server";

import { LocaleSwitcher } from "@/components/layout/locale-switcher";
import { ThemeToggle } from "@/components/layout/theme-toggle";

/**
 * Parcours de souscription (pack → paiement → confirmation). Groupe distinct
 * de `(auth)` : ces écrans ont besoin de toute la largeur pour la grille de
 * packs, là où login/register sont des cartes centrées et étroites.
 */
export default async function OnboardingLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const t = await getTranslations("app");

  return (
    <div className="bg-background flex min-h-dvh flex-col">
      <header className="border-border flex items-center justify-between border-b px-6 py-3.5">
        <span className="font-heading text-sm font-semibold tracking-tight">
          {t("name")}
        </span>
        <div className="flex items-center gap-1">
          <LocaleSwitcher />
          <ThemeToggle />
        </div>
      </header>
      <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-10 sm:px-6">
        {children}
      </main>
    </div>
  );
}
