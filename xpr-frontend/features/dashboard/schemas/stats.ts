import { z } from "zod";

import { DOCUMENT_STATUSES } from "@/components/patterns/status-badge";

/**
 * Contrat de la réponse `GET /api/v1/dashboard/stats`.
 *
 * Les schémas Zod sont la SOURCE DE VÉRITÉ (CLAUDE.md §6) : les types TS en
 * découlent par `z.infer`, et la réponse est validée à l'exécution. Un backend
 * qui renverrait un montant en dirhams plutôt qu'en centimes casserait ici,
 * bruyamment, plutôt que d'afficher silencieusement un CA divisé par 100.
 *
 * Tous les montants sont des ENTIERS de centimes (§7).
 */
const centsSchema = z.int();

export const revenuePointSchema = z.object({
  /** Mois au format ISO `YYYY-MM`. */
  month: z.string().regex(/^\d{4}-\d{2}$/),
  invoicedCents: centsSchema,
  collectedCents: centsSchema,
});

export const statusBreakdownSchema = z.object({
  status: z.enum(DOCUMENT_STATUSES),
  count: z.int().nonnegative(),
  totalCents: centsSchema,
});

/**
 * Un client du classement. `name` vient de `invoices.client_name`, encore un
 * texte libre : deux orthographes du même client comptent pour deux lignes.
 * Le regroupement deviendra exact quand la facture portera un `partnerId`.
 */
export const topClientSchema = z.object({
  name: z.string(),
  totalCents: centsSchema,
  invoiceCount: z.int().nonnegative(),
});

export const dashboardStatsSchema = z.object({
  currency: z.string().length(3),
  revenueCents: centsSchema,
  /** Variation par rapport à la période précédente, en ratio (0.124 = +12,4 %). */
  revenueTrend: z.number(),
  collectedCents: centsSchema,
  outstandingCents: centsSchema,
  overdueCents: centsSchema,
  overdueCount: z.int().nonnegative(),
  revenueSeries: z.array(revenuePointSchema),
  statusBreakdown: z.array(statusBreakdownSchema),

  /**
   * Portefeuille de tiers — état COURANT, non borné par la période : « clients
   * actifs » désigne le répertoire, pas ceux qui ont facturé ce mois-ci.
   * Une fiche à la fois client et fournisseur compte dans les deux.
   */
  activeClients: z.int().nonnegative(),
  activeSuppliers: z.int().nonnegative(),

  /** Trésorerie de la période. Les sorties sont une grandeur POSITIVE. */
  cashBalanceCents: centsSchema,
  cashInflowCents: centsSchema,
  cashOutflowCents: centsSchema,

  topClients: z.array(topClientSchema),
});

export type RevenuePoint = z.infer<typeof revenuePointSchema>;
export type StatusBreakdown = z.infer<typeof statusBreakdownSchema>;
export type TopClient = z.infer<typeof topClientSchema>;
export type DashboardStats = z.infer<typeof dashboardStatsSchema>;
