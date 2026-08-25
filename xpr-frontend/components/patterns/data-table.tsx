"use client";

import type { LucideIcon } from "lucide-react";

import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState } from "@/components/patterns/error-state";
import { Skeleton } from "@/components/ui/skeleton";
import { cn } from "@/lib/utils";

/**
 * Tableau dense générique (CLAUDE.md §11 : « densité type Linear, tableaux
 * compacts, actions au survol »). Il encapsule les QUATRE états imposés par §6
 * — loading / empty / error / success — pour qu'aucun écran métier n'ait à les
 * réécrire, et donc à en oublier un.
 *
 * Le typage est générique et strict : `accessor` reçoit la ligne typée, il n'y
 * a aucun `any` ni indexation dynamique par chaîne.
 */
export interface Column<T> {
  /** Clé technique, sert de `key` React sur la cellule. */
  id: string;
  /** En-tête déjà traduit. */
  header: string;
  /** Rendu de la cellule à partir de la ligne. */
  cell: (row: T) => React.ReactNode;
  /** Alignement en fin de ligne : réservé aux montants et aux actions. */
  align?: "start" | "end";
  /** Masque la colonne sous md — les tableaux denses débordent sur mobile. */
  hideBelow?: "md" | "lg";
  /**
   * Classes portées par la cellule ET par son en-tête.
   *
   * Les deux, parce qu'une colonne se règle en colonne : une largeur posée sur
   * le seul `<td>` laisse le `<th>` en décider autrement, et un alignement posé
   * sur le seul corps laisse l'intitulé de travers au-dessus.
   */
  className?: string;
}

interface DataTableProps<T> {
  rows: readonly T[];
  columns: readonly Column<T>[];
  /** Identité stable de la ligne — jamais l'index de tableau. */
  rowKey: (row: T) => string;
  status: "pending" | "error" | "success";
  errorDetail?: string;
  onRetry?: () => void;
  empty: { icon: LucideIcon; title: string; description?: string; action?: React.ReactNode };
  /** Nombre de lignes fantômes pendant le chargement. */
  skeletonRows?: number;
  onRowClick?: (row: T) => void;
  className?: string;
}

const HIDE_CLASS = {
  md: "hidden md:table-cell",
  lg: "hidden lg:table-cell",
} as const;

export function DataTable<T>({
  rows,
  columns,
  rowKey,
  status,
  errorDetail,
  onRetry,
  empty,
  skeletonRows = 6,
  onRowClick,
  className,
}: DataTableProps<T>) {
  const wrapper = cn(
    "ring-border bg-card overflow-hidden rounded-lg ring-1",
    className,
  );

  if (status === "error") {
    return (
      <div className={wrapper}>
        <ErrorState detail={errorDetail} onRetry={onRetry} />
      </div>
    );
  }

  if (status === "success" && rows.length === 0) {
    return (
      <div className={wrapper}>
        <EmptyState {...empty} />
      </div>
    );
  }

  return (
    <div className={wrapper}>
      {/* overflow-x isolé : §11 interdit que la page entière défile
          horizontalement à cause d'un tableau. */}
      <div className="overflow-x-auto">
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-border border-b">
              {columns.map((column) => (
                <th
                  key={column.id}
                  scope="col"
                  className={cn(
                    "text-muted-foreground bg-muted/40 px-3 py-2 text-xs font-medium tracking-wide whitespace-nowrap uppercase",
                    column.align === "end" ? "text-end" : "text-start",
                    column.hideBelow && HIDE_CLASS[column.hideBelow],
                    column.className,
                  )}
                >
                  {column.header}
                </th>
              ))}
            </tr>
          </thead>

          <tbody>
            {status === "pending"
              ? Array.from({ length: skeletonRows }, (_, index) => (
                  <tr key={index} className="border-border/60 border-b">
                    {columns.map((column) => (
                      <td
                        key={column.id}
                        className={cn(
                          "px-3 py-2.5",
                          column.hideBelow && HIDE_CLASS[column.hideBelow],
                        )}
                      >
                        <Skeleton className="h-4 w-full max-w-28" />
                      </td>
                    ))}
                  </tr>
                ))
              : rows.map((row) => (
                  <tr
                    key={rowKey(row)}
                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                    tabIndex={onRowClick ? 0 : undefined}
                    onKeyDown={
                      onRowClick
                        ? (event) => {
                            if (event.key === "Enter") onRowClick(row);
                          }
                        : undefined
                    }
                    className={cn(
                      "border-border/60 group/row hover:bg-muted/30 border-b transition-colors last:border-0",
                      onRowClick &&
                        "hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-ring cursor-pointer focus-visible:outline-2 focus-visible:-outline-offset-2",
                    )}
                  >
                    {columns.map((column) => (
                      <td
                        key={column.id}
                        className={cn(
                          "px-3 py-2.5 whitespace-nowrap",
                          column.align === "end" ? "text-end" : "text-start",
                          column.hideBelow && HIDE_CLASS[column.hideBelow],
                          column.className,
                        )}
                      >
                        {column.cell(row)}
                      </td>
                    ))}
                  </tr>
                ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
