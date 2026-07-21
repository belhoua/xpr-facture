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
export const DOCUMENT_STATUSES = [
  "draft",
  "sent",
  "partial",
  "paid",
  "overdue",
  "cancelled",
] as const;

export type DocumentStatus = (typeof DOCUMENT_STATUSES)[number];

const STATUS_COLOR: Record<DocumentStatus, string> = {
  draft: "text-status-draft bg-status-draft/10 ring-status-draft/20",
  sent: "text-status-sent bg-status-sent/10 ring-status-sent/20",
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
