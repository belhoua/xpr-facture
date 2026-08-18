"use client";

import dynamic from "next/dynamic";
import { useEffect, useState } from "react";

import { useUiStore } from "@/stores/ui";

/**
 * Porte le raccourci ⌘K, et RIEN d'autre.
 *
 * La palette elle-même (cmdk + le dialogue Radix + toute la table de
 * navigation traduite) était montée dans la coquille applicative, donc
 * téléchargée et exécutée sur CHAQUE écran — pour un panneau que l'on ouvre
 * quelques fois par session, et jamais pendant les premières secondes qui
 * suivent l'arrivée sur une page.
 *
 * Ce fichier tient en un écouteur clavier et un booléen. Le code de la palette
 * n'est demandé qu'à sa première ouverture, puis reste en cache pour les
 * suivantes — `mounted` ne redevient jamais faux, sinon chaque fermeture
 * démonterait un composant qu'on vient de charger.
 */
const CommandPalette = dynamic(
  () =>
    import("@/components/layout/command-palette").then((m) => m.CommandPalette),
  { ssr: false },
);

export function CommandPaletteHost() {
  const open = useUiStore((state) => state.commandPaletteOpen);
  const setOpen = useUiStore((state) => state.setCommandPaletteOpen);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      // metaKey = ⌘ sur macOS, ctrlKey pour Windows/Linux.
      if (event.key.toLowerCase() === "k" && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOpen(!useUiStore.getState().commandPaletteOpen);
      }
    }

    document.addEventListener("keydown", onKeyDown);

    return () => document.removeEventListener("keydown", onKeyDown);
  }, [setOpen]);

  // L'ouverture demandée depuis n'importe où (⌘K, bouton de la topbar) déclenche
  // le chargement. Le dialogue s'ouvre ensuite de lui-même : `open` vit dans le
  // store, la palette le lit au montage.
  useEffect(() => {
    if (open) {
      setMounted(true);
    }
  }, [open]);

  return mounted ? <CommandPalette /> : null;
}
