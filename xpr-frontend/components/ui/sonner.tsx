"use client";

import { useTheme } from "next-themes";
import { Toaster as Sonner, type ToasterProps } from "sonner";

/**
 * Zone de notifications de l'application.
 *
 * Montée une seule fois, dans le layout de `(app)` — un Toaster par page
 * empilerait plusieurs files et rendrait l'ordre d'affichage imprévisible.
 *
 * ── Pourquoi une dépendance plutôt qu'un composant maison ────────────────
 *
 * Un toast correct n'est pas un `<div>` qui disparaît : il faut une région
 * `aria-live` qui annonce sans voler le focus, l'empilement, la pause au
 * survol, la sortie au clavier et le sens de balayage inversé en RTL. Sonner
 * est la brique toast de shadcn/ui, la bibliothèque déjà retenue (§4) — la
 * réécrire donnerait moins bien pour plus cher.
 *
 * Le thème est LU, jamais deviné : `next-themes` connaît les trois états
 * (clair, sombre, système) et le toast doit suivre le même, sans quoi une
 * notification claire s'ouvrirait au milieu d'une interface sombre.
 *
 * La position suit la direction du document : en RTL, `top-right` tomberait du
 * côté où commence la lecture et masquerait le début des titres.
 */
export function Toaster(props: ToasterProps) {
  const { theme = "system" } = useTheme();

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      // Les couleurs viennent des tokens du design system, pas de la palette
      // par défaut de la bibliothèque : un toast doit appartenir à l'interface,
      // pas s'y superposer comme un élément étranger (§11).
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
        } as React.CSSProperties
      }
      {...props}
    />
  );
}
