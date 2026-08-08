"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Printer } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { fetchSituation, situationKeys } from "@/features/situations/api/situations";
import { SituationStatusBadge } from "@/features/situations/components/situation-status-badge";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Vue imprimable d'une situation.
 *
 * Impression NAVIGATEUR et non PDF serveur : la situation est une pièce de
 * suivi, pas un document opposable — Gotenberg reste réservé aux documents qui
 * engagent (§4), où la fidélité du rendu et l'archivage comptent.
 *
 * Aucun `useEffect` n'ouvre la boîte d'impression au montage : une page qui
 * s'imprime toute seule est ingérable si l'on voulait seulement la consulter.
 * L'utilisateur déclenche, ou utilise ⌘P.
 */
export function SituationPrintView({ id }: { id: string }) {
  const t = useTranslations("situations");
  const tCommon = useTranslations("common");
  const locale = useLocale();

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: situationKeys.detail(id),
    queryFn: () => fetchSituation(id),
  });

  if (isPending) {
    return <Skeleton className="h-96 w-full max-w-3xl" />;
  }

  if (isError) {
    return (
      <ErrorState detail={toApiProblem(error).detail} onRetry={() => void refetch()} />
    );
  }

  const rows: readonly { label: string; value: string }[] = [
    { label: t("table.total"), value: formatMoney(data.totalCents, locale, data.currency) },
    { label: t("table.advance"), value: formatMoney(data.paidCents, locale, data.currency) },
    {
      label: t("table.remaining"),
      value: formatMoney(data.remainingCents, locale, data.currency),
    },
  ];

  return (
    <div className="print-document">
      <div className="mb-4 flex items-center gap-2 print:hidden">
        <Button variant="outline" asChild>
          <Link href="/situations">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
            {tCommon("cancel")}
          </Link>
        </Button>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          {t("actions.print")}
        </Button>
      </div>

      <article className="ring-border bg-card mx-auto max-w-3xl rounded-lg p-8 ring-1 print:ring-0">
        <header className="border-border flex flex-wrap items-start justify-between gap-4 border-b pb-5">
          <div>
            <h1 className="font-heading text-xl font-semibold tracking-tight">
              {t("print.heading")}
            </h1>
            <p className="amount text-muted-foreground mt-1 text-sm">
              {data.number ?? "—"}
            </p>
          </div>
          <SituationStatusBadge status={data.status} />
        </header>

        <dl className="mt-5 grid gap-4 sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground text-xs tracking-wide uppercase">
              {t("table.client")}
            </dt>
            <dd className="mt-1 font-medium">{data.clientName}</dd>
            {data.clientIce ? (
              <dd className="amount text-muted-foreground mt-0.5 text-sm">
                {t("print.ice", { ice: data.clientIce })}
              </dd>
            ) : null}
            {data.clientAddress ? (
              <dd className="text-muted-foreground mt-0.5 text-sm whitespace-pre-line">
                {data.clientAddress}
              </dd>
            ) : null}
          </div>

          <div>
            <dt className="text-muted-foreground text-xs tracking-wide uppercase">
              {t("table.date")}
            </dt>
            <dd className="mt-1">
              {data.issuedAt ? formatDate(data.issuedAt, locale) : "—"}
            </dd>
          </div>
        </dl>

        <section className="mt-6">
          <h2 className="text-muted-foreground text-xs tracking-wide uppercase">
            {t("table.subject")}
          </h2>
          <p className="mt-1">{data.subject ?? "—"}</p>
        </section>

        <table className="mt-6 w-full text-sm">
          <tbody>
            {rows.map((row, index) => (
              <tr
                key={row.label}
                className={
                  index === rows.length - 1
                    ? "border-border border-t font-semibold"
                    : "border-border/60 border-b"
                }
              >
                <th scope="row" className="py-2 text-start font-normal">
                  {row.label}
                </th>
                <td className="amount py-2 text-end">{row.value}</td>
              </tr>
            ))}
          </tbody>
        </table>

        {data.notes ? (
          <p className="text-muted-foreground mt-6 text-sm whitespace-pre-line">
            {data.notes}
          </p>
        ) : null}

        {/* Mention explicite : sans elle, une situation imprimée pourrait être
            prise pour une facture. Elle ne porte aucune TVA et n'ouvre aucun
            droit à déduction. */}
        <p className="text-muted-foreground border-border mt-8 border-t pt-4 text-xs">
          {t("print.disclaimer")}
        </p>
      </article>
    </div>
  );
}
