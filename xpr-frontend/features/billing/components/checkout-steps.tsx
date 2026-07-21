import { Check } from "lucide-react";

import { cn } from "@/lib/utils";

/**
 * Fil d'étapes du parcours de souscription : compte → pack → paiement →
 * confirmation. Il rassure sur ce qui reste à faire, ce qui compte beaucoup
 * quand l'étape suivante est un formulaire de paiement.
 *
 * Le connecteur entre deux étapes utilise `flex-1` plutôt qu'une largeur fixe :
 * il se retourne donc naturellement en RTL sans règle dédiée.
 */
export const CHECKOUT_STEPS = ["account", "plan", "payment", "done"] as const;

export type CheckoutStep = (typeof CHECKOUT_STEPS)[number];

export function CheckoutSteps({
  current,
  labels,
}: {
  current: CheckoutStep;
  /** Libellés déjà traduits, dans l'ordre de CHECKOUT_STEPS. */
  labels: Record<CheckoutStep, string>;
}) {
  const currentIndex = CHECKOUT_STEPS.indexOf(current);

  return (
    <ol className="flex items-center gap-2" aria-label={labels[current]}>
      {CHECKOUT_STEPS.map((step, index) => {
        const done = index < currentIndex;
        const active = index === currentIndex;

        return (
          <li
            key={step}
            aria-current={active ? "step" : undefined}
            className={cn("flex items-center gap-2", index > 0 && "flex-1")}
          >
            {index > 0 && (
              <span
                aria-hidden
                className={cn(
                  "h-px flex-1",
                  done || active ? "bg-primary" : "bg-border",
                )}
              />
            )}
            <span
              className={cn(
                "flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium transition-colors",
                done && "bg-primary text-primary-foreground",
                active && "ring-primary text-primary ring-2",
                !done && !active && "bg-muted text-muted-foreground",
              )}
            >
              {done ? <Check className="size-3.5" aria-hidden /> : index + 1}
            </span>
            <span
              className={cn(
                "hidden text-sm whitespace-nowrap sm:inline",
                active ? "text-foreground font-medium" : "text-muted-foreground",
              )}
            >
              {labels[step]}
            </span>
          </li>
        );
      })}
    </ol>
  );
}
