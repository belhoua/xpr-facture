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
});

export type RevenuePoint = z.infer<typeof revenuePointSchema>;
export type StatusBreakdown = z.infer<typeof statusBreakdownSchema>;
export type DashboardStats = z.infer<typeof dashboardStatsSchema>;
