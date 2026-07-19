import { defineRouting } from "next-intl/routing";

/**
 * Locales de l'application (CLAUDE.md §2 : FR/AR dès le départ, EN en plus).
 * Le préfixe d'URL (/fr, /ar) rend la langue partageable et indexable.
 */
export const routing = defineRouting({
  locales: ["fr", "ar", "en"],
  defaultLocale: "fr",
});

export type Locale = (typeof routing.locales)[number];

/** Seule locale écrite de droite à gauche — pilote dir= et la typo arabe. */
export const RTL_LOCALES: readonly Locale[] = ["ar"];

export function isRtl(locale: string): boolean {
  return RTL_LOCALES.includes(locale as Locale);
}
