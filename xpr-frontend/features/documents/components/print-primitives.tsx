/**
 * Primitives de mise en page des documents imprimés, communes à tous les
 * gabarits A4 (devis, facture, et ceux qui suivront).
 *
 * Extraites de `quote-print-view` quand la facture a repris la même structure :
 * deux copies d'une cellule de tableau divergent au premier ajustement de
 * bordure ou de padding, et les documents commerciaux d'une même entreprise
 * doivent sortir de la même presse. Ce qui reste PROPRE à chaque type — l'ordre
 * des colonnes, les libellés, les mentions obligatoires — n'est pas ici.
 */

/**
 * Cellule de tableau. Centrée par défaut, comme le modèle client : seules la
 * désignation (start) et les montants (end) s'en écartent.
 */
export function Cell({
  children,
  as = "td",
  align = "center",
  colSpan,
  className = "",
}: {
  children: React.ReactNode;
  as?: "td" | "th";
  align?: "start" | "center" | "end";
  colSpan?: number;
  className?: string;
}) {
  const Tag = as;
  const alignment =
    align === "start" ? "text-start" : align === "end" ? "text-end" : "text-center";

  return (
    <Tag
      colSpan={colSpan}
      scope={as === "th" ? "col" : undefined}
      className={`border-foreground/60 border px-1.5 py-1 ${alignment} ${className}`}
    >
      {children}
    </Tag>
  );
}

/** Libellé en gras suivi de la valeur, ou d'un filet pointillé à compléter. */
export function FilledField({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  const filled = children !== null && children !== undefined && children !== "";

  return (
    <div className="flex items-baseline gap-2">
      <dt className="font-bold whitespace-nowrap">{label} :</dt>
      <dd className="min-w-0 flex-1">
        {filled ? (
          children
        ) : (
          <span
            className="border-foreground/50 block border-b border-dotted pt-3"
            aria-hidden
          />
        )}
      </dd>
    </div>
  );
}

/** Ligne du pied de totaux : libellé sur toute la largeur, montant à droite. */
export function TotalRow({
  label,
  value,
  strong = false,
}: {
  label: string;
  value: string;
  strong?: boolean;
}) {
  return (
    <tr className="border-foreground/60 border">
      <Cell className={strong ? "font-bold uppercase" : "font-bold"}>{label}</Cell>
      <Cell align="end" className={strong ? "amount font-bold" : "amount"}>
        {value}
      </Cell>
    </tr>
  );
}
