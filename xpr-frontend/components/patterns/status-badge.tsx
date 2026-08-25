import { cn } from "@/lib/utils";

/**
 * Sémantique de statut unique pour toute l'application (CLAUDE.md §11).
 * Un statut = une couleur = un mot, partout : une facture « payée » a la même
 * pastille dans la liste, dans le dashboard et dans le PDF.
 *
 * Le badge est volontairement une pastille + un point coloré plutôt qu'un aplat
 * saturé : dans un tableau dense, dix aplats de couleur crient plus fort que la
 * donnée qu'ils qualifient.
 */
/**
 * Miroir exact de `Documents\Enums\DocumentStatus` côté backend. Les neuf
 * états couvrent les huit types de documents ; c'est le serveur qui décide
 * lesquels sont atteignables pour un type donné (`allowedFor()`), l'interface
 * ne rejoue pas cette matrice — elle sait seulement les afficher.
 */
export const DOCUMENT_STATUSES = [
  "draft",
  "sent",
  "accepted",
  "refused",
  "converted",
  /** Affaire ouverte, règlement pas encore attendu. Propre aux situations. */
  "in_progress",
  "partial",
  "paid",
  "overdue",
  "cancelled",
] as const;

export type DocumentStatus = (typeof DOCUMENT_STATUSES)[number];

const STATUS_COLOR: Record<DocumentStatus, string> = {
  draft: "text-status-draft bg-status-draft/10 ring-status-draft/20",
  sent: "text-status-sent bg-status-sent/10 ring-status-sent/20",
  accepted:
    "text-status-accepted bg-status-accepted/10 ring-status-accepted/20",
  refused: "text-status-refused bg-status-refused/10 ring-status-refused/20",
  converted:
    "text-status-converted bg-status-converted/10 ring-status-converted/20",
  // Reprend le bleu de `sent` : « en cours » et « envoyé » disent tous deux
  // qu'une affaire suit son cours normal, sans rien réclamer. Pas de nouvelle
  // teinte introduite — §11 impose une seule couleur d'accent et une
  // sémantique de statut stable.
  in_progress: "text-status-sent bg-status-sent/10 ring-status-sent/20",
  partial: "text-status-partial bg-status-partial/10 ring-status-partial/20",
  paid: "text-status-paid bg-status-paid/10 ring-status-paid/20",
  overdue: "text-status-overdue bg-status-overdue/10 ring-status-overdue/20",
  cancelled:
    "text-status-cancelled bg-status-cancelled/10 ring-status-cancelled/20",
};

export function StatusBadge({
  status,
  label,
  className,
}: {
  status: DocumentStatus;
  /** Libellé déjà traduit — le badge ne fait aucun appel i18n lui-même. */
  label: string;
  className?: string;
}) {
  return (
    <span
      data-status={status}
      className={cn(
        "inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap",
        // ── À L'IMPRESSION ────────────────────────────────────────────────
        //
        // Le liseré d'écran est un `ring`, donc un `box-shadow` : il ne prend
        // aucune place dans le flux et son rendu sur papier dépend du moteur —
        // absent chez les uns, décalé d'un demi-pixel chez les autres, jamais
        // aligné sur la boîte qu'il est censé cerner. Il cède la place à une
        // vraie BORDURE, qui est une boîte et s'imprime comme telle.
        //
        // `border-current` et non un gris neutre : le texte et la pastille
        // portent déjà la teinte du statut, un cadre gris les désolidariserait.
        // La couleur du cadre reste donc celle de l'état qu'il encadre.
        //
        // 10 px et `leading-tight` : le tableau imprimé est plus dense que
        // l'écran, et un badge à 12 px y pousse la ligne au point de faire
        // déborder la colonne. La hauteur du badge suit son texte — il n'a
        // aucune hauteur fixe à annuler.
        "print:gap-1 print:border print:border-current print:text-[10px] print:leading-tight print:shadow-none",
        STATUS_COLOR[status],
        className,
      )}
    >
      <span
        aria-hidden
        className="size-1.5 rounded-full bg-current opacity-80"
      />
      {label}
    </span>
  );
}
