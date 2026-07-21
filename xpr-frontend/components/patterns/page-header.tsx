import { cn } from "@/lib/utils";

/**
 * En-tête de page unique pour tout l'espace client : titre, sous-titre
 * optionnel, actions alignées en fin de ligne (donc à droite en FR, à gauche
 * en AR — `justify-between` + propriétés logiques s'en chargent seuls).
 */
export function PageHeader({
  title,
  description,
  actions,
  className,
}: {
  title: string;
  description?: string;
  actions?: React.ReactNode;
  className?: string;
}) {
  return (
    <header
      className={cn(
        "flex flex-wrap items-start justify-between gap-3 pb-5",
        className,
      )}
    >
      <div className="min-w-0">
        <h1 className="font-heading truncate text-lg font-semibold tracking-tight">
          {title}
        </h1>
        {description ? (
          <p className="text-muted-foreground mt-0.5 text-sm">{description}</p>
        ) : null}
      </div>
      {actions ? (
        <div className="flex shrink-0 items-center gap-2">{actions}</div>
      ) : null}
    </header>
  );
}
