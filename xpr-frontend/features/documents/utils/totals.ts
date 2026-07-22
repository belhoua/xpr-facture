/**
 * APERÇU des totaux pendant la saisie. Réplique fidèle de
 * `Documents\Services\DocumentCalculator` côté PHP.
 *
 * Pourquoi dupliquer un calcul que le serveur fait déjà : sans lui, l'utilisateur
 * ne verrait le total de sa facture qu'après l'avoir enregistrée. C'est une
 * duplication assumée et bornée — la VÉRITÉ reste la réponse du serveur, que
 * l'écran réaffiche dès qu'elle arrive. Ce module ne sert jamais à construire
 * une charge utile : `toPayload` n'envoie que les lignes, jamais les totaux
 * (le FormRequest les refuserait, cf. DocumentStoreRequest).
 *
 * Les règles à ne pas trahir, sous peine d'un aperçu qui diffère d'un centime
 * du document enregistré :
 *  - tout est ENTIER : quantité en millièmes, pourcentages en points de base ;
 *  - arrondi COMMERCIAL à chaque étape (brut, remise, TVA), pas seulement au
 *    total — c'est ce que fait la facture papier, ligne par ligne ;
 *  - la TVA porte sur la base APRÈS remise.
 */

export interface LineInput {
  quantity: number;
  unitPriceCents: number;
  discountPercent: number;
  taxRatePercent: number;
}

export interface LineAmounts {
  grossCents: number;
  discountCents: number;
  subtotalCents: number;
  taxCents: number;
  totalCents: number;
}

export interface DocumentTotals {
  subtotalCents: number;
  discountCents: number;
  taxCents: number;
  totalCents: number;
}

/**
 * Division entière avec arrondi commercial (au plus proche, la demi-unité vers
 * le haut). `Math.trunc` et non `Math.floor` : les deux coïncident ici puisque
 * tous les numérateurs sont positifs, mais `trunc` exprime l'intention
 * (troncature vers zéro) de la même façon que `intdiv` en PHP.
 */
function divideRounded(numerator: number, denominator: number): number {
  return Math.trunc((numerator + Math.trunc(denominator / 2)) / denominator);
}

/** Décimal → entier d'échelle fixe. Dernière opération flottante de la chaîne. */
function toScaledInt(value: number, scale: number): number {
  return Math.round(value * scale);
}

export function computeLine(line: LineInput): LineAmounts {
  const quantityMilli = toScaledInt(line.quantity, 1000);
  const discountBasisPoints = toScaledInt(line.discountPercent, 100);
  const taxBasisPoints = toScaledInt(line.taxRatePercent, 100);

  // Une ligne incomplète (quantité vide en cours de frappe) vaut zéro plutôt
  // que NaN : l'aperçu ne doit jamais afficher « NaN MAD ».
  if (
    !Number.isFinite(quantityMilli) ||
    !Number.isFinite(line.unitPriceCents) ||
    quantityMilli <= 0
  ) {
    return {
      grossCents: 0,
      discountCents: 0,
      subtotalCents: 0,
      taxCents: 0,
      totalCents: 0,
    };
  }

  const grossCents = divideRounded(quantityMilli * line.unitPriceCents, 1000);
  const discountCents = divideRounded(grossCents * discountBasisPoints, 10_000);
  const subtotalCents = grossCents - discountCents;
  const taxCents = divideRounded(subtotalCents * taxBasisPoints, 10_000);

  return {
    grossCents,
    discountCents,
    subtotalCents,
    taxCents,
    totalCents: subtotalCents + taxCents,
  };
}

/**
 * Totaux du document : somme des lignes DÉJÀ arrondies. Recalculer sur les
 * montants bruts donnerait un pied de page qui n'est pas la somme exacte de ce
 * que le client lit au-dessus.
 */
export function computeTotals(lines: readonly LineInput[]): DocumentTotals {
  return lines.reduce<DocumentTotals>(
    (totals, line) => {
      const amounts = computeLine(line);

      return {
        subtotalCents: totals.subtotalCents + amounts.subtotalCents,
        discountCents: totals.discountCents + amounts.discountCents,
        taxCents: totals.taxCents + amounts.taxCents,
        totalCents: totals.totalCents + amounts.totalCents,
      };
    },
    { subtotalCents: 0, discountCents: 0, taxCents: 0, totalCents: 0 },
  );
}
