import { api } from "@/lib/api/client";
import {
  dashboardStatsSchema,
  type DashboardStats,
} from "@/features/dashboard/schemas/stats";

/**
 * Accès réseau du module Dashboard. Passe par le client Axios partagé
 * (cookies de session Sanctum + Accept-Language) — jamais par `axios` direct.
 *
 * ⚠️ L'endpoint `/dashboard/stats` N'EXISTE PAS ENCORE côté Laravel : il relève
 * de la Phase 1. Tant qu'il n'est pas livré, l'écran affiche son état d'erreur.
 * C'est délibéré : aucune donnée inventée ne circule ici (§12).
 */
export const dashboardKeys = {
  all: ["dashboard"] as const,
  stats: (period: string) => [...dashboardKeys.all, "stats", period] as const,
};

export async function fetchDashboardStats(
  period: string,
): Promise<DashboardStats> {
  const { data } = await api.get("/dashboard/stats", { params: { period } });

  // Validation à l'exécution : le contrat est vérifié, pas supposé.
  return dashboardStatsSchema.parse(data);
}
