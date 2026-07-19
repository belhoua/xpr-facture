import { getTranslations } from "next-intl/server";

import { LocaleSwitcher } from "@/components/layout/locale-switcher";

/**
 * Écrans publics d'authentification : marque + bascule FR/AR toujours
 * visible (arbitrage Q4), formulaire centré. Les classes logiques
 * (justify-between, p-4) restent correctes en RTL sans adaptation.
 */
export default async function AuthLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const t = await getTranslations("app");

  return (
    <div className="bg-muted/40 flex min-h-dvh flex-col">
      <header className="flex items-center justify-between px-6 py-4">
        <span className="text-sm font-semibold tracking-tight">{t("name")}</span>
        <LocaleSwitcher />
      </header>
      <main className="flex flex-1 items-center justify-center p-4">
        {children}
      </main>
    </div>
  );
}
