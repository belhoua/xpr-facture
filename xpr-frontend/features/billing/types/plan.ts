/**
 * Catalogue des packs d'abonnement BCAT.
 *
 * Les prix sont en CENTIMES de dirham (CLAUDE.md §7) : 19 900 = 199,00 MAD.
 * Ce catalogue est de la CONFIGURATION PRODUIT, pas une donnée factice — il
 * migrera vers la table `plans` en Phase 3, moment où ce fichier deviendra un
 * simple type et où les valeurs viendront de l'API.
 */

/**
 * Packs commercialisés.
 *
 * Le pack GRATUIT a été retiré le 2026-08-26, sur décision commerciale de
 * l'exploitant. Retiré du CATALOGUE et non masqué sur la vitrine : la landing
 * et le tunnel de souscription lisent la même liste, et n'en filtrer qu'une
 * aurait fait choisir « Standard » sur la page d'accueil pour retrouver une
 * offre gratuite à l'étape suivante.
 *
 * Aucun chemin ne casse : `/subscribe/payment?plan=free` répondait déjà 404 —
 * la page refuse tout plan à prix nul — et la Phase 3 n'ayant pas encore de
 * table `subscriptions`, aucun compte n'y est abonné.
 */
export const PLAN_IDS = ["standard", "pro"] as const;

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
