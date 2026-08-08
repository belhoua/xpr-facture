"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Printer } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import type { ApiCompany } from "@/features/auth/types/auth";
import { useMe } from "@/features/auth/hooks/use-auth";
import { documentKeys, fetchDocument } from "@/features/documents/api/documents";
import {
  LegalFooter,
  Letterhead,
} from "@/features/documents/components/letterhead";
import { DocumentLineTable } from "@/features/documents/components/print-line-table";
import {
  FilledField,
  TotalRow,
} from "@/features/documents/components/print-primitives";
import type { Document } from "@/features/documents/schemas/document";
import { amountInWords } from "@/lib/amount-in-words";
import { toApiProblem } from "@/lib/api/client";
import { BRAND } from "@/lib/brand";
import { formatAmount, formatDate } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Facture prête à imprimer. MÊME papier que le devis — `Letterhead`,
 * `LegalFooter` et les primitives de tableau sont partagés — mais son propre
 * contenu : une facture n'est pas une proposition commerciale.
 *
 * Ce que la facture porte et que le devis ignore :
 *  - l'ÉCHÉANCE et les conditions de règlement, qui fondent l'exigibilité ;
 *  - l'état du RÈGLEMENT (encaissé, solde), que le serveur calcule ;
 *  - le détail de TVA par LIGNE, et non seulement en pied. Les taux affichés
 *    sont ceux FIGÉS sur chaque ligne à la saisie (§3), jamais un taux écrit
 *    en dur : une facture réimprimée doit ressortir identique même après une
 *    réforme du barème.
 *
 * Impression NAVIGATEUR et non PDF serveur, comme le devis : Gotenberg (§4)
 * entrera en jeu pour le document archivé et envoyé par e-mail.
 *
 * Aucun `useEffect` n'ouvre la boîte d'impression au montage : une page qui
 * s'imprime toute seule est ingérable quand on voulait seulement la relire.
 */
export function InvoicePrintView({ id }: { id: string }) {
  const t = useTranslations("documents");

  const documentQuery = useQuery({
    queryKey: documentKeys.detail(id),
    queryFn: () => fetchDocument(id),
  });

  // L'identité de l'émetteur vient de la société active, jamais d'une constante.
  const meQuery = useMe();

  if (documentQuery.isPending || meQuery.isPending) {
    return <Skeleton className="mx-auto h-[26rem] w-full max-w-[210mm]" />;
  }

  if (documentQuery.isError || meQuery.isError) {
    const error = documentQuery.error ?? meQuery.error;

    return (
      <ErrorState
        detail={toApiProblem(error).detail}
        onRetry={() => {
          void documentQuery.refetch();
          void meQuery.refetch();
        }}
      />
    );
  }

  const invoice = documentQuery.data;
  const company = meQuery.data.active_company;

  // Le gabarit est celui d'une FACTURE : l'ouvrir sur un devis imprimerait des
  // libellés faux — « Date d'échéance » sur une proposition n'a pas de sens.
  // Même garde que `QuotePrintView`.
  if (invoice.type !== "invoice") {
    return <ErrorState detail={t("print.invoice.wrongType")} />;
  }

  if (company === null) {
    return <ErrorState detail={t("print.noCompany")} />;
  }

  return (
    <div className="print-document">
      <div className="mx-auto mb-4 flex w-full max-w-[210mm] items-center gap-2 print:hidden">
        <Button variant="outline" asChild>
          <Link href="/invoices">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
            {t("print.invoice.back")}
          </Link>
        </Button>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          {t("print.invoice.action")}
        </Button>

        {/* Un brouillon n'a pas encore de numéro : il ne l'obtient qu'à
            l'émission (§3). On laisse relire et imprimer le projet de facture,
            mais on le dit — un document sans numéro n'est pas une pièce
            comptable et ne doit pas être remis au client comme telle. */}
        {invoice.number === null ? (
          <p className="text-muted-foreground text-sm">{t("print.draftNotice")}</p>
        ) : null}
      </div>

      <article className="print-sheet bg-card ring-border mx-auto w-full max-w-[210mm] p-[14mm] text-[11pt] leading-snug ring-1 print:ring-0">
        <Letterhead />
        <InvoiceBody invoice={invoice} company={company} />
        <LegalFooter company={company} />
      </article>
    </div>
  );
}

function InvoiceBody({
  invoice,
  company,
}: {
  invoice: Document;
  company: ApiCompany;
}) {
  const t = useTranslations("documents");
  const tStatus = useTranslations("status");
  const locale = useLocale();

  const items = invoice.items ?? [];
  const taxSummary = invoice.taxSummary ?? [];

  // Société exonérée sans TVA collectée : la ligne de taxe n'a pas lieu d'être,
  // la mention légale la remplace (§3).
  const vatNotApplicable = company.vat_exempt && invoice.taxCents === 0;

  const asDate = (value: string | null): string | null =>
    value
      ? formatDate(value, locale, {
          day: "2-digit",
          month: "2-digit",
          year: "numeric",
        })
      : null;

  const issuedOn = asDate(invoice.issuedAt);
  const dueOn = asDate(invoice.dueAt);

  // Un acompte encaissé change ce que le client doit : tant qu'il y en a un, le
  // pied affiche le réglé et le solde, sans quoi le « Total TTC » se lirait
  // comme le montant restant dû.
  const showSettlement = invoice.paidCents > 0;

  return (
    <>
      {/* « RABAT, le 05/08/2026 ». La ville est celle SAISIE SUR LE DOCUMENT :
          un bureau de contrôle établit ses pièces là où se trouve le chantier,
          pas à son siège. À défaut, le repli de `BRAND`. */}
      <p className="mt-4 text-end font-bold">
        {t("print.issuedIn", {
          city: (invoice.issueCity ?? BRAND.defaultCity).toLocaleUpperCase(locale),
          date: issuedOn ?? "—",
        })}
      </p>

      <h1 className="font-heading mt-3 text-center text-xl font-bold tracking-tight uppercase">
        {t("print.invoice.title", {
          number: invoice.number ?? t("draftLabel"),
        })}
      </h1>

      <dl className="mt-4 space-y-1">
        <FilledField label={t("print.invoice.client")}>
          {invoice.clientName}
          {invoice.clientIce
            ? ` — ${t("print.legal.ice", { value: invoice.clientIce })}`
            : ""}
        </FilledField>
        {invoice.clientAddress ? (
          <FilledField label={t("print.invoice.address")}>
            {invoice.clientAddress}
          </FilledField>
        ) : null}
        <FilledField label={t("print.subject")}>{invoice.subject}</FilledField>
        <FilledField label={t("print.invoice.issuedAt")}>{issuedOn}</FilledField>
        <FilledField label={t("print.invoice.dueAt")}>{dueOn}</FilledField>
        <FilledField label={t("print.invoice.paymentTerms")}>
          {invoice.terms ? (
            <span className="whitespace-pre-line">{invoice.terms}</span>
          ) : null}
        </FilledField>
        {/* Statut de RÈGLEMENT et non statut du document : le brouillon et
            l'annulé disent l'état de la pièce, pas celui du paiement. Le libellé
            traduit vient du même référentiel que les badges de la liste. */}
        <FilledField label={t("print.invoice.paymentStatus")}>
          {tStatus(invoice.status)}
        </FilledField>
      </dl>

      <DocumentLineTable items={items} />

      {/* Pied de totaux dans un tableau distinct et non dans un `tfoot`, que les
          navigateurs répètent sur chaque page imprimée — une facture de deux
          pages afficherait alors deux fois son total. La colonne des montants
          reprend les 18 % du « Total TTC » du détail : le total tombe sous la
          colonne qu'il totalise. */}
      <table className="border-foreground/60 w-full table-fixed border-collapse border border-t-0 text-[9.5pt] break-inside-avoid">
        <colgroup>
          <col style={{ width: "82%" }} />
          <col style={{ width: "18%" }} />
        </colgroup>
        <tbody>
          {invoice.discountCents > 0 ? (
            <>
              <TotalRow
                label={t("print.totals.gross")}
                value={formatAmount(
                  invoice.subtotalCents + invoice.discountCents,
                  locale,
                )}
              />
              <TotalRow
                label={t("totals.discount")}
                value={`−${formatAmount(invoice.discountCents, locale)}`}
              />
            </>
          ) : null}

          <TotalRow
            label={t("print.totals.excludingTax")}
            value={formatAmount(invoice.subtotalCents, locale)}
          />

          {/* Un récapitulatif PAR TAUX est obligatoire dès qu'il y en a
              plusieurs (§3) ; le serveur le fournit déjà ventilé. */}
          {vatNotApplicable ? null : taxSummary.length > 0 ? (
            taxSummary.map((line) => (
              <TotalRow
                key={line.rate}
                label={t("print.totals.vat", { rate: line.rate })}
                value={formatAmount(line.taxCents, locale)}
              />
            ))
          ) : (
            <TotalRow
              label={t("totals.tax")}
              value={formatAmount(invoice.taxCents, locale)}
            />
          )}

          <TotalRow
            label={t("print.totals.includingTax")}
            value={formatAmount(invoice.totalCents, locale)}
            strong
          />

          {showSettlement ? (
            <>
              <TotalRow
                label={t("print.invoice.paid")}
                value={formatAmount(invoice.paidCents, locale)}
              />
              <TotalRow
                label={t("print.invoice.remaining")}
                value={formatAmount(invoice.remainingCents, locale)}
                strong
              />
            </>
          ) : null}
        </tbody>
      </table>

      {vatNotApplicable ? (
        <p className="mt-2 font-bold">{t("print.vatNotApplicable")}</p>
      ) : null}

      {invoice.status === "overdue" ? (
        <p className="mt-2 font-bold">{t("print.invoice.overdueNotice")}</p>
      ) : null}

      {/* Montant en toutes lettres : mention obligatoire (§3). Il porte le TTC
          de la facture, et non le solde — c'est le montant facturé qui est
          arrêté, l'acompte déjà reçu figure au pied de totaux. */}
      <p className="mt-4 break-inside-avoid">
        {t("print.invoice.settled", {
          amount: amountInWords(invoice.totalCents, locale, invoice.currency),
        })}
      </p>

      {invoice.notes ? (
        <p className="mt-3 whitespace-pre-line">{invoice.notes}</p>
      ) : null}

      <div className="mt-6 break-inside-avoid text-end">
        <p className="font-bold underline">{t("print.signature")}</p>
        {/* Espace réservé au cachet et à la signature manuscrite. */}
        <div className="h-[26mm]" aria-hidden />
        <p className="text-muted-foreground text-[9pt]">{t("print.stamp")}</p>
      </div>
    </>
  );
}
