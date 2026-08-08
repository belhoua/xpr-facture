/**
 * Formatage localisé. Les montants transitent en CENTIMES (entiers) depuis
 * l'API — cf. CLAUDE.md §7 « Montants : BIGINT en centimes, jamais de FLOAT ».
 * La division par 100 n'a lieu qu'ICI, au tout dernier moment, juste avant
 * l'affichage : aucun calcul métier ne doit jamais manipuler un flottant.
 */

export const DEFAULT_CURRENCY = "MAD";

/**
 * Formate un montant en centimes vers sa représentation localisée.
 * `formatMoney(125000, "fr")` → « 1 250,00 MAD »
 */
export function formatMoney(
  cents: number,
  locale: string,
  currency: string = DEFAULT_CURRENCY,
): string {
  return new Intl.NumberFormat(localeTag(locale), {
    style: "currency",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);
}

/**
 * Variante compacte pour les KPI et les axes de graphique, où « 1,2 M MAD »
 * est plus lisible que « 1 234 567,00 MAD ».
 */
export function formatMoneyCompact(
  cents: number,
  locale: string,
  currency: string = DEFAULT_CURRENCY,
): string {
  return new Intl.NumberFormat(localeTag(locale), {
    style: "currency",
    currency,
    notation: "compact",
    maximumFractionDigits: 1,
  }).format(cents / 100);
}

export function formatNumber(value: number, locale: string): string {
  return new Intl.NumberFormat(localeTag(locale)).format(value);
}

/**
 * Montant en centimes SANS symbole de devise, toujours à deux décimales.
 *
 * C'est la forme d'un document imprimé : la devise y est annoncée une fois —
 * en pied de tableau et dans la somme en toutes lettres — et la répéter sur
 * chaque ligne alourdirait une colonne qu'on lit en diagonale.
 */
export function formatAmount(cents: number, locale: string): string {
  return new Intl.NumberFormat(localeTag(locale), {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(cents / 100);
}

/** Quantité décimale (« 1 », « 2,5 ») — les zéros de queue ne sont pas rendus. */
export function formatQuantity(value: number, locale: string): string {
  return new Intl.NumberFormat(localeTag(locale), {
    maximumFractionDigits: 3,
  }).format(value);
}

/** Variation en points de pourcentage, signe explicite : « +12,4 % ». */
export function formatPercent(ratio: number, locale: string): string {
  return new Intl.NumberFormat(localeTag(locale), {
    style: "percent",
    maximumFractionDigits: 1,
    signDisplay: "exceptZero",
  }).format(ratio);
}

export function formatDate(
  value: string | Date,
  locale: string,
  options: Intl.DateTimeFormatOptions = {
    day: "2-digit",
    month: "short",
    year: "numeric",
  },
): string {
  const date = typeof value === "string" ? new Date(value) : value;

  return new Intl.DateTimeFormat(localeTag(locale), options).format(date);
}

/**
 * L'arabe marocain (`ar-MA`) utilise par défaut les chiffres arabes orientaux
 * (٠١٢٣) sur certains runtimes. Les factures marocaines s'écrivent en chiffres
 * latins : on force explicitement `-u-nu-latn`.
 */
function localeTag(locale: string): string {
  return locale === "ar" ? "ar-MA-u-nu-latn" : `${locale}-MA`;
}
