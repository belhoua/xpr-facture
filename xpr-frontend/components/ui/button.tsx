import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Loader2 } from "lucide-react"
import { Slot } from "radix-ui"

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center rounded-lg border border-transparent bg-clip-padding text-sm font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 active:not-aria-[haspopup]:translate-y-px disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-3 aria-invalid:ring-destructive/20 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-primary/80",
        outline:
          "border-border bg-background hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-[color-mix(in_oklch,var(--secondary),var(--foreground)_5%)] aria-expanded:bg-secondary aria-expanded:text-secondary-foreground",
        ghost:
          "hover:bg-muted hover:text-foreground aria-expanded:bg-muted aria-expanded:text-foreground dark:hover:bg-muted/50",
        destructive:
          "bg-destructive/10 text-destructive hover:bg-destructive/20 focus-visible:border-destructive/40 focus-visible:ring-destructive/20 dark:bg-destructive/20 dark:hover:bg-destructive/30 dark:focus-visible:ring-destructive/40",
        link: "text-primary underline-offset-4 hover:underline",
      },
      size: {
        default:
          "h-8 gap-1.5 px-2.5 has-data-[icon=inline-end]:pe-2 has-data-[icon=inline-start]:ps-2",
        xs: "h-6 gap-1 rounded-[min(var(--radius-md),10px)] px-2 text-xs in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pe-1.5 has-data-[icon=inline-start]:ps-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-7 gap-1 rounded-[min(var(--radius-md),12px)] px-2.5 text-[0.8rem] in-data-[slot=button-group]:rounded-lg has-data-[icon=inline-end]:pe-1.5 has-data-[icon=inline-start]:ps-1.5 [&_svg:not([class*='size-'])]:size-3.5",
        lg: "h-9 gap-1.5 px-2.5 has-data-[icon=inline-end]:pe-2 has-data-[icon=inline-start]:ps-2",
        icon: "size-8",
        "icon-xs":
          "size-6 rounded-[min(var(--radius-md),10px)] in-data-[slot=button-group]:rounded-lg [&_svg:not([class*='size-'])]:size-3",
        "icon-sm":
          "size-7 rounded-[min(var(--radius-md),12px)] in-data-[slot=button-group]:rounded-lg",
        "icon-lg": "size-9",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

/**
 * `loading` : l'action est PARTIE, l'application attend la réponse.
 *
 * Un `disabled={mutation.isPending}` seul ne dit rien à l'utilisateur : le
 * bouton pâlit, mais rien ne distingue « en cours d'envoi » de « bouton
 * inactif ». Sur une liaison lente — la cible de ce produit, c'est-à-dire un
 * bureau au Maroc sur une connexion ordinaire — le clic sur « Enregistrer »
 * semblait n'avoir aucun effet, et le réflexe est alors de recliquer.
 *
 * Le spinner s'insère en tête du contenu, dans le `gap` déjà prévu par les
 * variantes de taille. Quand le bouton porte DÉJÀ une icône, celle-ci est
 * masquée (cf. la règle sur `className`) : le spinner la remplace, plutôt que
 * de faire cohabiter deux symboles pour un seul état.
 *
 * Sous `asChild`, aucun spinner n'est injecté — voir le rendu des enfants.
 *
 * `disabled` en découle : un bouton qui charge n'est jamais cliquable, sans
 * qu'aucun appelant ait à poser les deux propriétés. `aria-busy` porte la même
 * information aux lecteurs d'écran, qui ne voient pas l'animation.
 */
function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  loading = false,
  disabled,
  children,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    loading?: boolean
  }) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      // `asChild` délègue le rendu à l'enfant : y injecter un spinner
      // casserait la règle d'enfant unique de Slot. L'état reste porté par
      // l'attribut, que l'enfant peut styler.
      aria-busy={loading || undefined}
      disabled={asChild ? undefined : loading || disabled}
      className={cn(
        buttonVariants({ variant, size, className }),
        // Le spinner PREND LA PLACE de l'icône du bouton au lieu de s'y
        // ajouter : « ⟳ ✉ Envoi… » montrerait deux symboles pour un seul
        // état, et élargirait le bouton au moment précis du clic. Masquer par
        // le CSS évite de conditionner l'icône sur chaque site d'appel.
        loading && !asChild && "[&>svg:not(.animate-spin)]:hidden",
      )}
      {...props}
    >
      {/* `children` est passé SEUL et intact sous `asChild`. Un frère — même
          un `null` issu d'un ternaire — ferait des enfants un TABLEAU, et
          `Slot` exige un élément unique : « Slot failed to slot onto its
          children ». L'erreur est d'exécution, pas de typage, et frappe tous
          les boutons-liens (`<Button asChild><Link/></Button>`) et eux seuls. */}
      {asChild ? (
        children
      ) : (
        <>
          {loading ? <Loader2 className="animate-spin" aria-hidden /> : null}
          {children}
        </>
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
