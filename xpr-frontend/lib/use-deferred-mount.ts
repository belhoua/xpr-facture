"use client";

import { useEffect, useState } from "react";

/**
 * « Ce panneau a-t-il déjà été ouvert au moins une fois ? »
 *
 * Sert à ne monter — donc à ne TÉLÉCHARGER — un dialogue lourd qu'au moment
 * où l'utilisateur le demande. Les formulaires de document, les panneaux de
 * détail et la fenêtre des règlements pèsent chacun plusieurs centaines de
 * lignes ; montés d'emblée dans une vue de liste, ils partaient dans le lot
 * initial de la page alors que l'écran affiche d'abord un tableau.
 *
 * Le retour ne redevient JAMAIS faux. C'est délibéré, pour deux raisons :
 *  - démonter à la fermeture supprimerait l'animation de sortie de Radix, qui
 *    a besoin que le composant survive à `open = false` ;
 *  - le code est déjà chargé, le démonter ne rendrait rien au navigateur —
 *    seulement de l'état perdu et un remontage à la réouverture.
 */
export function useDeferredMount(open: boolean): boolean {
  const [mounted, setMounted] = useState(open);

  useEffect(() => {
    if (open) {
      setMounted(true);
    }
  }, [open]);

  return mounted;
}
