import Image from "next/image";

import { BRAND } from "@/lib/brand";
import { cn } from "@/lib/utils";

/**
 * Le logo de l'exploitant, dans le chrome de l'application (sidebar, tiroir
 * mobile). Un seul composant pour tous les emplacements : la hauteur change, le
 * fichier et son texte alternatif ne changent pas.
 *
 * DEUX VISUELS, un par thème : le fichier clair est encre sombre sur fond
 * blanc, le fichier sombre est son pendant sur fond noir. La bascule se fait en
 * CSS (`dark:hidden` / `hidden dark:flex`) et non par `useTheme` : le hook rend
 * `undefined` au premier passage, ce qui ferait clignoter le mauvais logo à
 * chaque chargement de page — précisément sur le repère qui doit être stable.
 * Les deux images sont dans le document, une seule est peinte.
 *
 * La pastille suit la même bascule (`bg-white` / `bg-neutral-950`) : les deux
 * fichiers sont OPAQUES et portent leur propre fond. Sans elle, le logo clair
 * dessinait un rectangle blanc autour de la marque en thème sombre.
 *
 * `priority` et non le chargement paresseux par défaut : la marque est le
 * premier repère visuel de la page, elle ne doit pas apparaître après coup.
 *
 * C'est la LARGEUR qui pilote, et non la hauteur : le logo est une bande.
 * Contraindre sa hauteur dans une barre de 56 px le réduirait à une vignette,
 * alors que la place disponible est horizontale. Les largeurs des appelants
 * sont bornées par leur conteneur, elles ne sont pas choisies librement.
 */

/**
 * Ratio de la BOÎTE, commun aux deux thèmes. Il vient du fichier clair, seul
 * des deux à être détouré.
 *
 * Une seule géométrie et non une par fichier : deux boîtes de hauteurs
 * différentes feraient sauter la barre en changeant de thème, et la variante
 * sombre — bien plus haute à largeur égale (ratio ≈ 2,6 : 1) — déborderait des
 * 56 px de la barre dès qu'on élargit un peu le logo.
 */
const ASPECT_RATIO = BRAND.logo.light.width / BRAND.logo.light.height;

export function BrandMark({
  width = 160,
  className,
}: {
  /** Largeur de rendu en pixels ; la hauteur suit le ratio de la boîte. */
  width?: number;
  className?: string;
}) {
  const height = Math.round(width / ASPECT_RATIO);

  return (
    <>
      <Variant
        variant="light"
        width={width}
        height={height}
        className={cn("flex bg-white dark:hidden", className)}
      />
      <Variant
        variant="dark"
        width={width}
        height={height}
        className={cn("hidden bg-neutral-950 dark:flex", className)}
      />
    </>
  );
}

function Variant({
  variant,
  width,
  height,
  className,
}: {
  variant: "light" | "dark";
  width: number;
  height: number;
  className?: string;
}) {
  const file = BRAND.logo[variant];

  return (
    <span
      className={cn(
        "shrink-0 items-center justify-center overflow-hidden rounded-md",
        className,
      )}
      style={{ width, height }}
    >
      <Image
        src={file.src}
        // Une seule des deux variantes est visible, mais toutes deux sont dans
        // le document : nommer les deux ferait annoncer la marque en double par
        // un lecteur d'écran. Le nom est porté par la variante claire, celle
        // qui reste rendue quand aucun thème n'est encore appliqué.
        alt={variant === "light" ? BRAND.name : ""}
        aria-hidden={variant === "dark"}
        width={file.width}
        height={file.height}
        priority
        // `cover` sur la variante SOMBRE, `contain` sur la claire.
        //
        // Le fichier sombre n'est pas détouré : il porte ≈ 21 % de fond noir en
        // haut et autant en bas, d'où son ratio plus trapu. `contain` le
        // logerait entier dans la boîte, glyphe compris — donc un glyphe visible
        // nettement plus petit que celui du thème clair, pour la même place
        // occupée. `cover` recadre CETTE MARGE (13,9 % de part et d'autre, sur
        // 21 % disponibles : le glyphe n'est jamais entamé) et rend les deux
        // marques à la même échelle. Aucune déformation dans les deux cas — ni
        // `cover` ni `contain` ne touchent au ratio de l'image.
        className={cn(
          "h-full w-full",
          variant === "dark" ? "object-cover" : "object-contain",
        )}
      />
    </span>
  );
}
