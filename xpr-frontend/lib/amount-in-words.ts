/**
 * Montant en toutes lettres — mention obligatoire sur les documents
 * commerciaux marocains (CLAUDE.md §3, « Montant en toutes lettres (FR et AR) »).
 *
 * L'entrée est en CENTIMES, comme partout dans la chaîne (§7) : la partie
 * entière et les centimes sont séparés par un `Math.trunc` / modulo sur des
 * entiers, jamais par une division flottante — `Math.round(2.675 * 100)` est
 * précisément le genre d'erreur qu'une mention légale ne peut pas se permettre.
 *
 * Les centimes sont TOUJOURS énoncés, y compris nuls (« et zéro centimes ») :
 * un montant arrêté doit être non ambigu, et c'est la forme qu'emploient les
 * documents de référence.
 */

const FR_SMALL = [
  "zéro",
  "un",
  "deux",
  "trois",
  "quatre",
  "cinq",
  "six",
  "sept",
  "huit",
  "neuf",
  "dix",
  "onze",
  "douze",
  "treize",
  "quatorze",
  "quinze",
  "seize",
] as const;

const FR_TENS: Record<number, string> = {
  2: "vingt",
  3: "trente",
  4: "quarante",
  5: "cinquante",
  6: "soixante",
  8: "quatre-vingt",
};

/**
 * 0–99 en français.
 *
 * `plural` porte la règle d'accord de « vingt » : *quatre-vingts* quand rien
 * ne suit ou qu'un nom suit (millions), *quatre-vingt* devant un autre adjectif
 * numéral (quatre-vingt mille, quatre-vingt-un).
 */
function frBelowHundred(value: number, plural: boolean): string {
  if (value < 17) {
    return FR_SMALL[value] ?? "";
  }

  if (value < 20) {
    return `dix-${FR_SMALL[value - 10] ?? ""}`;
  }

  const tens = Math.trunc(value / 10);
  const unit = value % 10;

  // 70 et 90 se construisent sur soixante / quatre-vingt + 10..19.
  if (tens === 7 || tens === 9) {
    const base = tens === 7 ? "soixante" : "quatre-vingt";

    // « soixante et onze », mais « quatre-vingt-onze » — le trait d'union
    // remplace la conjonction dès que la dizaine est composée.
    if (tens === 7 && unit === 1) {
      return "soixante et onze";
    }

    return `${base}-${frBelowHundred(10 + unit, false)}`;
  }

  const base = FR_TENS[tens] ?? "";

  if (unit === 0) {
    return tens === 8 && plural ? `${base}s` : base;
  }

  // « vingt et un » … « soixante et un », mais « quatre-vingt-un ».
  if (unit === 1 && tens !== 8) {
    return `${base} et un`;
  }

  return `${base}-${FR_SMALL[unit] ?? ""}`;
}

/** 0–999 en français. `plural` : voir frBelowHundred. */
function frBelowThousand(value: number, plural: boolean): string {
  const hundreds = Math.trunc(value / 100);
  const rest = value % 100;
  const parts: string[] = [];

  if (hundreds === 1) {
    parts.push("cent");
  } else if (hundreds > 1) {
    // « deux cents » seul, « deux cent trois » composé, « deux cent mille »
    // devant un numéral.
    parts.push(`${FR_SMALL[hundreds] ?? ""} cent${rest === 0 && plural ? "s" : ""}`);
  }

  if (rest > 0) {
    parts.push(frBelowHundred(rest, plural));
  }

  return parts.join(" ");
}

interface Scale {
  readonly value: number;
  readonly one: string;
  readonly many: string;
  /** Un NOM (million) impose l'accord de vingt/cent ; « mille » l'interdit. */
  readonly noun: boolean;
}

const FR_SCALES: readonly Scale[] = [
  { value: 1_000_000_000, one: "milliard", many: "milliards", noun: true },
  { value: 1_000_000, one: "million", many: "millions", noun: true },
  { value: 1_000, one: "mille", many: "mille", noun: false },
];

function frInteger(value: number): string {
  if (value === 0) {
    return "zéro";
  }

  const parts: string[] = [];
  let rest = value;

  for (const scale of FR_SCALES) {
    const count = Math.trunc(rest / scale.value);
    rest %= scale.value;

    if (count === 0) {
      continue;
    }

    if (!scale.noun) {
      // « mille » et non « un mille ».
      parts.push(count === 1 ? "mille" : `${frBelowThousand(count, false)} mille`);
      continue;
    }

    parts.push(
      `${frBelowThousand(count, true)} ${count > 1 ? scale.many : scale.one}`,
    );
  }

  if (rest > 0) {
    parts.push(frBelowThousand(rest, true));
  }

  return parts.join(" ");
}

const EN_SMALL = [
  "zero",
  "one",
  "two",
  "three",
  "four",
  "five",
  "six",
  "seven",
  "eight",
  "nine",
  "ten",
  "eleven",
  "twelve",
  "thirteen",
  "fourteen",
  "fifteen",
  "sixteen",
  "seventeen",
  "eighteen",
  "nineteen",
] as const;

const EN_TENS = [
  "",
  "",
  "twenty",
  "thirty",
  "forty",
  "fifty",
  "sixty",
  "seventy",
  "eighty",
  "ninety",
] as const;

function enBelowThousand(value: number): string {
  const hundreds = Math.trunc(value / 100);
  const rest = value % 100;
  const parts: string[] = [];

  if (hundreds > 0) {
    parts.push(`${EN_SMALL[hundreds] ?? ""} hundred`);
  }

  if (rest > 0 && rest < 20) {
    parts.push(EN_SMALL[rest] ?? "");
  } else if (rest >= 20) {
    const tens = EN_TENS[Math.trunc(rest / 10)] ?? "";
    const unit = rest % 10;
    parts.push(unit === 0 ? tens : `${tens}-${EN_SMALL[unit] ?? ""}`);
  }

  return parts.join(" ");
}

const EN_SCALES: readonly { value: number; name: string }[] = [
  { value: 1_000_000_000, name: "billion" },
  { value: 1_000_000, name: "million" },
  { value: 1_000, name: "thousand" },
];

function enInteger(value: number): string {
  if (value === 0) {
    return "zero";
  }

  const parts: string[] = [];
  let rest = value;

  for (const scale of EN_SCALES) {
    const count = Math.trunc(rest / scale.value);
    rest %= scale.value;

    if (count > 0) {
      parts.push(`${enBelowThousand(count)} ${scale.name}`);
    }
  }

  if (rest > 0) {
    parts.push(enBelowThousand(rest));
  }

  return parts.join(" ");
}

const AR_SMALL = [
  "صفر",
  "واحد",
  "اثنان",
  "ثلاثة",
  "أربعة",
  "خمسة",
  "ستة",
  "سبعة",
  "ثمانية",
  "تسعة",
  "عشرة",
  "أحد عشر",
  "اثنا عشر",
  "ثلاثة عشر",
  "أربعة عشر",
  "خمسة عشر",
  "ستة عشر",
  "سبعة عشر",
  "ثمانية عشر",
  "تسعة عشر",
] as const;

const AR_TENS: Record<number, string> = {
  2: "عشرون",
  3: "ثلاثون",
  4: "أربعون",
  5: "خمسون",
  6: "ستون",
  7: "سبعون",
  8: "ثمانون",
  9: "تسعون",
};

const AR_HUNDREDS: Record<number, string> = {
  1: "مائة",
  2: "مائتان",
  3: "ثلاثمائة",
  4: "أربعمائة",
  5: "خمسمائة",
  6: "ستمائة",
  7: "سبعمائة",
  8: "ثمانمائة",
  9: "تسعمائة",
};

/**
 * 0–999 en arabe. L'unité PRÉCÈDE la dizaine (خمسة وعشرون), et les groupes se
 * lient par la conjonction « و ».
 */
function arBelowThousand(value: number): string {
  const hundreds = Math.trunc(value / 100);
  const rest = value % 100;
  const parts: string[] = [];

  if (hundreds > 0) {
    parts.push(AR_HUNDREDS[hundreds] ?? "");
  }

  if (rest > 0 && rest < 20) {
    parts.push(AR_SMALL[rest] ?? "");
  } else if (rest >= 20) {
    const unit = rest % 10;
    const tens = AR_TENS[Math.trunc(rest / 10)] ?? "";
    parts.push(unit === 0 ? tens : `${AR_SMALL[unit] ?? ""} و${tens}`);
  }

  return parts.join(" و");
}

/**
 * Échelles arabes. Le nom compté suit le triptyque singulier / duel / pluriel :
 * ألف, ألفان, آلاف — et repasse au singulier au-delà de dix (mille onze : أحد
 * عشر ألفاً), forme qu'on simplifie ici en ألف, lisible et non fautive.
 */
const AR_SCALES: readonly {
  value: number;
  one: string;
  two: string;
  few: string;
}[] = [
  { value: 1_000_000_000, one: "مليار", two: "ملياران", few: "مليارات" },
  { value: 1_000_000, one: "مليون", two: "مليونان", few: "ملايين" },
  { value: 1_000, one: "ألف", two: "ألفان", few: "آلاف" },
];

function arInteger(value: number): string {
  if (value === 0) {
    return "صفر";
  }

  const parts: string[] = [];
  let rest = value;

  for (const scale of AR_SCALES) {
    const count = Math.trunc(rest / scale.value);
    rest %= scale.value;

    if (count === 0) {
      continue;
    }

    if (count === 1) {
      parts.push(scale.one);
    } else if (count === 2) {
      parts.push(scale.two);
    } else if (count <= 10) {
      parts.push(`${arBelowThousand(count)} ${scale.few}`);
    } else {
      parts.push(`${arBelowThousand(count)} ${scale.one}`);
    }
  }

  if (rest > 0) {
    parts.push(arBelowThousand(rest));
  }

  return parts.join(" و");
}

interface CurrencyNames {
  readonly majorOne: string;
  readonly majorMany: string;
  readonly minorOne: string;
  readonly minorMany: string;
}

/**
 * Noms de la devise et de sa subdivision.
 *
 * MAD seul est nommé : c'est la devise des documents (§3). Toute autre devise
 * retombe sur son code ISO plutôt que sur une traduction inventée — « cent EUR
 * et zéro centime » est moins élégant mais reste exact, là où un pluriel
 * approximatif sur une mention légale ne le serait pas.
 */
function currencyNames(currency: string, locale: string): CurrencyNames {
  const isDirham = currency === "MAD";

  if (locale === "ar") {
    return {
      majorOne: isDirham ? "درهم" : currency,
      majorMany: isDirham ? "دراهم" : currency,
      minorOne: "سنتيم",
      minorMany: "سنتيمات",
    };
  }

  if (locale === "en") {
    return {
      majorOne: isDirham ? "dirham" : currency,
      majorMany: isDirham ? "dirhams" : currency,
      minorOne: "centime",
      minorMany: "centimes",
    };
  }

  return {
    majorOne: isDirham ? "dirham" : currency,
    majorMany: isDirham ? "dirhams" : currency,
    minorOne: "centime",
    minorMany: "centimes",
  };
}

/**
 * Accord du nom compté.
 *
 * Trois règles distinctes, et aucune n'est décorative sur une mention légale :
 *  - français : singulier jusqu'à un inclus (« zéro dirham », « un dirham ») ;
 *  - anglais : singulier pour un seul (« zero dirhams », « one dirham ») ;
 *  - arabe : pluriel de 3 à 10 (« خمسة دراهم »), singulier ailleurs — le nom
 *    compté repasse au singulier à partir de onze.
 */
function agree(count: number, locale: string, names: CurrencyNames, minor: boolean): string {
  const one = minor ? names.minorOne : names.majorOne;
  const many = minor ? names.minorMany : names.majorMany;

  if (locale === "ar") {
    return count >= 3 && count <= 10 ? many : one;
  }

  if (locale === "en") {
    return count === 1 ? one : many;
  }

  return count >= 2 ? many : one;
}

function spell(value: number, locale: string): string {
  if (locale === "ar") {
    return arInteger(value);
  }

  return locale === "en" ? enInteger(value) : frInteger(value);
}

/**
 * En français, million et milliard sont des NOMS : ils appellent « de » devant
 * la devise (« dix millions DE dirhams »), là où mille, adjectif numéral, s'en
 * passe (« dix mille dirhams »). La distinction ne joue que lorsque l'énoncé
 * s'arrête sur l'échelle — « dix millions cinq cents dirhams » n'en veut pas.
 */
const FR_NOUN_SCALE_ENDING = /\bmilli(?:on|ard)s?$/;

/**
 * Montant en centimes → énoncé en toutes lettres, devise comprise.
 *
 * `amountInWords(23100000, "fr")`
 *   → « deux cent trente et un mille dirhams et zéro centime »
 *
 * Un montant négatif (avoir) est précédé de sa mention : le signe « − » ne se
 * lit pas sur du papier.
 */
export function amountInWords(
  cents: number,
  locale: string,
  currency = "MAD",
): string {
  const names = currencyNames(currency, locale);
  const absolute = Math.abs(Math.trunc(cents));
  const major = Math.trunc(absolute / 100);
  const minor = absolute % 100;

  const majorWords = spell(major, locale);
  const minorWords = spell(minor, locale);

  // « de » impose le pluriel de la devise : un million de dirhamS.
  const needsDe = locale !== "ar" && locale !== "en" && FR_NOUN_SCALE_ENDING.test(majorWords);
  const majorNoun = needsDe
    ? names.majorMany
    : agree(major, locale, names, false);
  const minorNoun = agree(minor, locale, names, true);

  const sentence =
    locale === "ar"
      ? // La conjonction arabe s'accroche au mot suivant, sans espace.
        `${majorWords} ${majorNoun} و${minorWords} ${minorNoun}`
      : locale === "en"
        ? `${majorWords} ${majorNoun} and ${minorWords} ${minorNoun}`
        : `${majorWords} ${needsDe ? "de " : ""}${majorNoun} et ${minorWords} ${minorNoun}`;

  if (cents >= 0) {
    return sentence;
  }

  const negative =
    locale === "ar" ? "سالب" : locale === "en" ? "minus" : "moins";

  return `${negative} ${sentence}`;
}
