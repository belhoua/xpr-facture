"use client";

import { useEffect, useState } from "react";

/**
 * Valeur retardée, pour ne pas transformer une saisie en rafale de requêtes.
 *
 * Les écrans de liste posent le champ de recherche dans la clé TanStack Query
 * (`documentKeys.list({ search })`). Chaque caractère frappé changeait donc la
 * clé, et chaque changement de clé part en requête : taper « MENARA » lançait
 * six appels à l'API, dont cinq dont personne n'attendait le résultat — cinq
 * requêtes SQL avec leur `ILIKE`, cinq réponses à sérialiser, et la dernière
 * en concurrence avec les précédentes.
 *
 * Le champ, lui, reste piloté par l'état immédiat : la frappe ne traîne pas.
 * Seule la valeur INTERROGÉE attend que l'utilisateur s'arrête.
 *
 * 300 ms : au-delà, l'attente se remarque sur une recherche courte ; en deçà,
 * une frappe normale repasse au travers.
 */
export function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);

    // Chaque frappe annule le minuteur précédent : c'est ce qui fait qu'une
    // saisie continue ne déclenche qu'un seul appel, à la fin.
    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
