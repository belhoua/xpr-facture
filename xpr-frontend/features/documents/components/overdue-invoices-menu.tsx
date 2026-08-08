"use client";

import { useQuery } from "@tanstack/react-query";
import { Bell } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { Skeleton } from "@/components/ui/skeleton";
import {
  documentKeys,
  fetchDocuments,
  type DocumentFilters,
} from "@/features/documents/api/documents";
import { formatMoney } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Cloche des factures en retard, dans la topbar.
 *
 * Le « retard » est ici le STATUT `overdue` de la facture, tel que le serveur
 * le connaît — pas une échéance recalculée dans le navigateur. Deux dates
 * comparées côté client donneraient un décompte différent de celui de la liste
 * et des états, et le désaccord serait invisible.
 *
 * La requête réutilise la CLÉ de la liste des factures filtrées sur `overdue` :
 * ouvrir cet écran et ouvrir cette cloche parlent des mêmes données, elles
 * doivent partager le même cache — sinon le badge annoncerait un chiffre que la
 * liste juste en dessous contredirait.
 */
const OVERDUE_FILTERS: DocumentFilters = {
  type: "invoice",
  search: "",
  status: "overdue",
};

/** Au-delà, le popover renvoie vers la liste : ce n'est pas un écran de travail. */
const PREVIEW_LIMIT = 6;

export function OverdueInvoicesMenu() {
  const t = useTranslations("documents");
  const locale = useLocale();

  const { data, isPending, isError } = useQuery({
    queryKey: documentKeys.list(OVERDUE_FILTERS),
    queryFn: () => fetchDocuments(OVERDUE_FILTERS),
    // Une minute : un impayé ne devient pas urgent à la seconde près, et cette
    // requête part sur CHAQUE écran de l'application.
    staleTime: 60_000,
  });

  const invoices = data?.data ?? [];
  const total = data?.meta.total ?? 0;
  const preview = invoices.slice(0, PREVIEW_LIMIT);
  const hidden = total - preview.length;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative"
          aria-label={t("overdue.trigger", { count: total })}
        >
          <Bell aria-hidden />

          {/* Le badge n'apparaît QUE s'il y a quelque chose à signaler : une
              pastille à zéro alarmerait sans rien dire. Le compte vient de
              `meta.total`, donc de la base — pas du nombre de lignes ramenées,
              qui est plafonné par la pagination. */}
          {total > 0 && (
            <span
              className="bg-destructive text-destructive-foreground absolute -top-0.5 -end-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[0.625rem] leading-none font-semibold tabular-nums"
              aria-hidden
            >
              {total > 99 ? "99+" : total}
            </span>
          )}
        </Button>
      </PopoverTrigger>

      <PopoverContent align="end" className="w-80 p-0">
        <div className="border-border flex items-center justify-between border-b px-3 py-2">
          <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {t("overdue.title")}
          </span>
          {total > 0 && (
            <span className="text-destructive text-xs font-semibold tabular-nums">
              {total}
            </span>
          )}
        </div>

        {isPending ? (
          <div className="space-y-2 p-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-full" />
          </div>
        ) : isError ? (
          <p className="text-muted-foreground px-3 py-6 text-center text-sm">
            {t("overdue.error")}
          </p>
        ) : preview.length === 0 ? (
          <p className="text-muted-foreground px-3 py-6 text-center text-sm">
            {t("overdue.none")}
          </p>
        ) : (
          <ul className="max-h-80 overflow-y-auto py-1">
            {preview.map((invoice) => (
              <li key={invoice.id}>
                {/* `?document=` ouvre la facture dans sa liste, panneau de
                    détail déployé — le même chemin que le transfert. Une
                    notification qui ne mène pas à la pièce ne sert à rien. */}
                <Link
                  href={`/invoices?document=${invoice.id}`}
                  className="hover:bg-muted/60 flex items-baseline gap-2 px-3 py-1.5 text-sm"
                >
                  <span className="tabular shrink-0 font-medium">
                    {invoice.number ?? t("draftLabel")}
                  </span>
                  <span className="text-muted-foreground min-w-0 flex-1 truncate">
                    {invoice.clientName}
                  </span>
                  {/* Le RESTE À PAYER, pas le total : sur un impayé, c'est la
                      somme à réclamer qui intéresse, acompte déduit. Repli sur
                      le total si le solde est à zéro — une facture soldée n'a
                      rien à faire ici, et afficher « 0,00 » ferait croire à un
                      impayé sans montant plutôt qu'à une donnée à corriger. */}
                  <span className="amount shrink-0 font-medium">
                    {formatMoney(
                      invoice.remainingCents || invoice.totalCents,
                      locale,
                      invoice.currency,
                    )}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        )}

        {hidden > 0 && (
          <p className="text-muted-foreground border-border border-t px-3 py-1.5 text-xs">
            {t("overdue.more", { count: hidden })}
          </p>
        )}

        <div className="border-border border-t p-1">
          <Link
            href="/invoices"
            className="hover:bg-muted/60 block rounded-md px-2 py-1.5 text-sm font-medium"
          >
            {t("overdue.viewAll")}
          </Link>
        </div>
      </PopoverContent>
    </Popover>
  );
}
