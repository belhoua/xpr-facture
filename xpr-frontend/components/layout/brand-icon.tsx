import Image from "next/image";

import { BRAND } from "@/lib/brand";
import { cn } from "@/lib/utils";

/**
 * L'emblème carré de l'exploitant, à côté du logo dans le chrome — et SEUL
 * repère de marque quand la sidebar est repliée.
 *
 * Séparé de `BrandMark` parce qu'il répond à une contrainte que la bande ne
 * peut pas satisfaire : dans 52 px de large, un logo de ratio 3,6 : 1 tombe à
 * 14 px de haut, alors qu'un carré y reste pleinement lisible.
 *
 * La pastille reprend le fond sombre du fichier plutôt que celui du thème :
 * l'image est opaque, et sans elle ses angles vifs dépasseraient du rayon
 * arrondi en thème clair.
 *
 * `priority`, comme `BrandMark` : c'est le premier repère visuel de la page.
 */
export function BrandIcon({
  size = 32,
  className,
}: {
  /** Côté de la pastille en pixels. L'image est carrée. */
  size?: number;
  className?: string;
}) {
  return (
    <span
      className={cn(
        "flex shrink-0 items-center justify-center overflow-hidden rounded-md bg-neutral-950",
        className,
      )}
      style={{ width: size, height: size }}
    >
      <Image
        src={BRAND.icon.src}
        alt=""
        width={BRAND.icon.size}
        height={BRAND.icon.size}
        priority
        // `alt` vide et `aria-hidden` : l'emblème accompagne toujours le nom de
        // la marque — porté par le logo à côté, ou par le `aria-label` du lien
        // qui les contient. L'annoncer une seconde fois n'apprendrait rien.
        aria-hidden
        className="h-full w-full object-cover"
      />
    </span>
  );
}
