import createMiddleware from "next-intl/middleware";

import { routing } from "@/lib/i18n/routing";

/**
 * Détection de langue (arbitrage Q4) : next-intl négocie via Accept-Language
 * à la première visite puis mémorise le choix (cookie NEXT_LOCALE) — la
 * bascule manuelle FR/AR du LocaleSwitcher prime toujours.
 */
export default createMiddleware(routing);

export const config = {
  // Tout sauf : les chemins proxifiés vers le backend (api, sanctum — ils ne
  // portent pas de langue et ne doivent PAS être redirigés vers /fr/...), les
  // internes Next et les fichiers statiques.
  matcher: "/((?!api|sanctum|_next|_vercel|.*\\..*).*)",
};
