import { cn } from "@/lib/utils";

/**
 * Barre d'avancement d'un projet.
 *
 * Écrite ici et non tirée d'un composant shadcn : §11 impose des bordures 1px
 * et des rayons discrets, et cette barre doit tenir dans une cellule de tableau
 * dense sans imposer sa hauteur à la ligne. Elle sert aussi bien la liste que
 * le tiroir de détail.
 *
 * `role="progressbar"` avec ses trois attributs ARIA plutôt qu'un simple `div`
 * coloré : la valeur est reprise en texte à côté, mais un lecteur d'écran doit
 * comprendre la barre elle-même, pas seulement la légende (§9.8, WCAG AA).
 */
export function ProgressBar({
  value,
  label,
  className,
}: {
  /** Pourcentage 0–100, borné par le serveur ET par la contrainte SQL. */
  value: number;
  /** Étiquette accessible, déjà traduite. */
  label: string;
  className?: string;
}) {
  // Borné une seconde fois ici : une donnée hors bornes viendrait d'un défaut
  // ailleurs, et la barre déborderait de sa piste plutôt que de le signaler.
  const clamped = Math.min(100, Math.max(0, Math.round(value)));

  return (
    <div className={cn("flex items-center gap-2", className)}>
      <div
        role="progressbar"
        aria-valuenow={clamped}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label={label}
        className="bg-muted ring-border h-1.5 w-full min-w-16 overflow-hidden rounded-full ring-1"
      >
        <div
          // `bg-primary` et non une teinte propre : l'avancement n'est pas un
          // statut, il n'a donc pas à emprunter la palette sémantique — le
          // badge à côté porte déjà cette information.
          className="bg-primary h-full rounded-full transition-[width] duration-300"
          style={{ width: `${clamped}%` }}
        />
      </div>
      <span className="amount text-muted-foreground w-9 shrink-0 text-xs">
        {clamped} %
      </span>
    </div>
  );
}
