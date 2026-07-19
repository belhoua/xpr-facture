"use client";

import { useLocale } from "next-intl";

import { Button } from "@/components/ui/button";
import { usePathname, useRouter } from "@/lib/i18n/navigation";
import type { Locale } from "@/lib/i18n/routing";

/**
 * Bascule FR/AR visible sur les écrans d'auth (arbitrage Q4) : changer de
 * langue re-rend la même page dans l'autre locale — et passe tout l'écran
 * en RTL pour l'arabe (dir géré par le layout racine).
 */
const OPTIONS: ReadonlyArray<{ code: Locale; label: string }> = [
  { code: "fr", label: "FR" },
  { code: "ar", label: "العربية" },
];

export function LocaleSwitcher() {
  const locale = useLocale();
  const router = useRouter();
  const pathname = usePathname();

  return (
    <div className="flex items-center gap-1" role="group" aria-label="Language">
      {OPTIONS.map((option) => (
        <Button
          key={option.code}
          type="button"
          size="sm"
          variant={option.code === locale ? "secondary" : "ghost"}
          aria-pressed={option.code === locale}
          onClick={() => router.replace(pathname, { locale: option.code })}
        >
          {option.label}
        </Button>
      ))}
    </div>
  );
}
