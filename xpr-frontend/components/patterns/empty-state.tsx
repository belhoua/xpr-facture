import type { LucideIcon } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * État vide (CLAUDE.md §6 : « gestion systématique des 4 états »). Un écran
 * vide n'est pas une erreur : il doit expliquer ce qui manque ET proposer
 * l'action qui le remplit, sinon l'utilisateur est en cul-de-sac.
 */
export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: React.ReactNode;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center px-6 py-16 text-center",
        className,
      )}
    >
      <div className="bg-muted text-muted-foreground ring-border flex size-11 items-center justify-center rounded-lg ring-1">
        <Icon className="size-5" aria-hidden />
      </div>
      <p className="mt-4 text-sm font-medium">{title}</p>
      {description ? (
        <p className="text-muted-foreground mt-1 max-w-sm text-sm text-balance">
          {description}
        </p>
      ) : null}
      {action ? <div className="mt-5">{action}</div> : null}
    </div>
  );
}
