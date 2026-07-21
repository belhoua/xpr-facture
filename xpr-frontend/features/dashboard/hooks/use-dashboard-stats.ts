"use client";

import { useQuery } from "@tanstack/react-query";

import {
  dashboardKeys,
  fetchDashboardStats,
} from "@/features/dashboard/api/dashboard";

/**
 * Statistiques du tableau de bord. L'état serveur vit ici, dans TanStack Query,
 * jamais dans Zustand (§6).
 */
export function useDashboardStats(period: string) {
  return useQuery({
    queryKey: dashboardKeys.stats(period),
    queryFn: () => fetchDashboardStats(period),
    // Les chiffres d'un dashboard tolèrent une minute de fraîcheur ; recharger
    // à chaque focus de fenêtre ne ferait que marteler l'API.
    staleTime: 60_000,
  });
}
