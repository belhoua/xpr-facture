"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ThemeProvider } from "next-themes";
import { useState } from "react";

/**
 * État serveur global (TanStack Query) + thème clair/sombre.
 * Le QueryClient est instancié dans un useState pour ne jamais partager de
 * cache entre deux rendus serveur/clients différents.
 */
export function Providers({ children }: { children: React.ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            // 5 minutes. Les données de cette application changent à la vitesse
            // d'une saisie humaine, pas d'un flux de marché : re-solliciter
            // l'API toutes les 30 secondes pour une liste de factures que
            // personne n'a touchée coûtait des requêtes sans jamais rien
            // apprendre.
            //
            // Ce délai ne retarde AUCUNE mise à jour issue de l'application
            // elle-même : chaque mutation invalide ses clés
            // (`invalidateQueries`), ce qui refetch immédiatement. Il ne
            // concerne que les écritures faites AILLEURS — un collègue, un
            // autre onglet — dont l'apparition peut attendre.
            staleTime: 5 * 60 * 1000,
            // Revenir sur l'onglet ne recharge plus tous les écrans montés.
            // C'était le gros du trafic inutile : passer sur sa boîte mail et
            // revenir déclenchait autant de requêtes que d'écrans ouverts.
            refetchOnWindowFocus: false,
            retry: 1,
          },
        },
      }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      {/* attribute="class" : la classe .dark de globals.css est la source du
          thème. defaultTheme="system" respecte la préférence de l'OS. */}
      <ThemeProvider
        attribute="class"
        defaultTheme="system"
        enableSystem
        disableTransitionOnChange
      >
        {children}
      </ThemeProvider>
    </QueryClientProvider>
  );
}
