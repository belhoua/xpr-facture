/**
 * Catalogue des packs d'abonnement XPR Suite.
 *
 * Les prix sont en CENTIMES de dirham (CLAUDE.md §7) : 19 900 = 199,00 MAD.
 * Ce catalogue est de la CONFIGURATION PRODUIT, pas une donnée factice — il
 * migrera vers la table `plans` en Phase 3, moment où ce fichier deviendra un
 * simple type et où les valeurs viendront de l'API.
 */

export const PLAN_IDS = ["free", "standard", "pro"] as const;

export type PlanId = (typeof PLAN_IDS)[number];

export type BillingPeriod = "monthly" | "yearly";

export interface Plan {
  id: PlanId;
  /** Prix mensuel en centimes de MAD, hors taxes. */
  monthlyPriceCents: number;
  /**
   * Prix annuel en centimes. Volontairement stocké et non calculé : une remise
   * commerciale se négocie, elle ne se déduit pas d'une formule.
   */
  yearlyPriceCents: number;
  /** Nombre de fonctionnalités listées dans les dictionnaires i18n. */
  featureCount: number;
  /** Un seul plan peut être mis en avant, sinon plus rien ne l'est. */
  highlighted: boolean;
}

export const PLANS: readonly Plan[] = [
  {
    id: "free",
    monthlyPriceCents: 0,
    yearlyPriceCents: 0,
    featureCount: 6,
    highlighted: false,
  },
  {
    id: "standard",
    // 199 MAD/mois, ou 1 990 MAD/an (soit 2 mois offerts).
    monthlyPriceCents: 19_900,
    yearlyPriceCents: 199_000,
    featureCount: 7,
    highlighted: true,
  },
  {
    id: "pro",
    // 499 MAD/mois, ou 4 990 MAD/an.
    monthlyPriceCents: 49_900,
    yearlyPriceCents: 499_000,
    featureCount: 7,
    highlighted: false,
  },
];

export function findPlan(id: string): Plan | undefined {
  return PLANS.find((plan) => plan.id === id);
}

/**
 * Montant mensuel équivalent affiché sur la carte. En facturation annuelle on
 * montre le coût mensuel LISSÉ (prix annuel / 12) plutôt que le prix mensuel :
 * c'est la comparaison honnête, et elle rend la remise visible.
 */
export function displayedMonthlyCents(
  plan: Plan,
  period: BillingPeriod,
): number {
  return period === "yearly"
    ? Math.round(plan.yearlyPriceCents / 12)
    : plan.monthlyPriceCents;
}

/** Montant réellement débité à la souscription. */
export function chargedCents(plan: Plan, period: BillingPeriod): number {
  return period === "yearly" ? plan.yearlyPriceCents : plan.monthlyPriceCents;
}
