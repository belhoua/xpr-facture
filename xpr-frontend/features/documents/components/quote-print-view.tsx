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
import { EditableSheet } from "@/features/documents/components/editable-sheet";
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
 * Devis prêt à imprimer, calqué sur le modèle Word fourni par le client
 * (`docs/devis modele.docx`) : papier à en-tête, encart de titre, maître
 * d'ouvrage et objet, tableau des prestations, pied de totaux, somme en toutes
 * lettres, encart de signature.
 *
 * Impression NAVIGATEUR et non PDF serveur, comme les situations : Gotenberg
 * (§4) entrera en jeu pour le document ARCHIVÉ et envoyé par e-mail, où la
 * fidélité du rendu doit être garantie hors de tout navigateur. Tant que cette
 * chaîne n'existe pas, une page imprimable rend le même service sans faire
 * dépendre l'impression d'un conteneur.
 *
 * Aucun `useEffect` n'ouvre la boîte d'impression au montage : une page qui
 * s'imprime toute seule est ingérable quand on voulait seulement la relire.
 *
 * ── Édition libre avant impression (2026-08-15) ────────────────────────────
 *
 * La feuille est un `contentEditable` : tout se corrige au clavier, comme dans
 * un traitement de texte. Voir `EditableSheet`, qui explique pourquoi la
 * sous-arborescence est figée côté React — et ce que cela coûte : les totaux ne
 * se recalculent plus, et le devis remis au client peut différer de celui
 * enregistré.
 */
export function QuotePrintView({ id }: { id: string }) {
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

  const quote = documentQuery.data;
  const company = meQuery.data.active_company;

  // Le gabarit est celui d'un DEVIS : l'ouvrir sur une facture ou une situation
  // imprimerait des libellés faux. Même garde que `situations/client`.
  if (quote.type !== "quote") {
    return <ErrorState detail={t("print.wrongType")} />;
  }

  if (company === null) {
    return <ErrorState detail={t("print.noCompany")} />;
  }

  return (
    <div className="print-document">
      <div className="no-print mx-auto mb-4 flex w-full max-w-[210mm] flex-wrap items-center gap-2 print:hidden">
        <Button variant="outline" asChild>
          <Link href="/quotes">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
            {t("print.back")}
          </Link>
        </Button>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          {t("print.action")}
        </Button>

        {/* Dit une fois, sobrement : la page se corrige au clavier, et rien de
            ce qu'on y tape n'est enregistré. Sans cette phrase, un utilisateur
            qui retouche sa pièce peut croire l'avoir corrigée en base. */}
        <p className="text-muted-foreground text-sm">{t("print.edit.hint")}</p>

        {/* Un brouillon n'a pas encore de numéro : il ne l'obtient qu'à
            l'émission (§3). On l'imprime quand même — une proposition
            commerciale circule avant d'être émise — mais on le dit. */}
        {quote.number === null ? (
          <p className="text-muted-foreground text-sm">{t("print.draftNotice")}</p>
        ) : null}
      </div>

      <EditableSheet>
        <Letterhead />
        <QuoteBody quote={quote} company={company} />
        <LegalFooter company={company} />
      </EditableSheet>
    </div>
  );
}

function QuoteBody({
  quote,
  company,
}: {
  quote: Document;
  company: ApiCompany;
}) {
  const t = useTranslations("documents");
  const locale = useLocale();

  const items = quote.items ?? [];
  const taxSummary = quote.taxSummary ?? [];

  // Société exonérée sans TVA collectée : la ligne de taxe n'a pas lieu d'être,
  // la mention légale la remplace (§3).
  const vatNotApplicable = company.vat_exempt && quote.taxCents === 0;

  const issuedOn = quote.issuedAt
    ? formatDate(quote.issuedAt, locale, {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      })
    : null;

  return (
    <>
      {/* « RABAT, le 05/08/2026 ». La ville est celle SAISIE SUR LE DEVIS : un
          bureau de contrôle établit ses documents là où se trouve le chantier,
          pas à son siège. À défaut, le repli de `BRAND`. */}
      <p className="mt-4 text-end font-bold">
        {t("print.issuedIn", {
          city: (quote.issueCity ?? BRAND.defaultCity).toLocaleUpperCase(locale),
          date: issuedOn ?? "—",
        })}
      </p>

      <h1 className="font-heading mt-3 text-center text-xl font-bold tracking-tight uppercase">
        {t("print.title", { number: quote.number ?? t("draftLabel") })}
      </h1>

      <dl className="mt-4 space-y-1">
        <FilledField label={t("print.owner")}>
          {quote.clientName}
          {quote.clientIce ? ` — ${t("print.legal.ice", { value: quote.clientIce })}` : ""}
        </FilledField>
        <FilledField label={t("print.subject")}>{quote.subject}</FilledField>
      </dl>

      {/* Même tableau que la facture, colonne pour colonne : un client qui
          compare son devis et sa facture doit retrouver la même grille. */}
      <DocumentLineTable items={items} />

      {/* Pied de totaux : le libellé court sur les sept premières colonnes et le
          montant tombe sous « Total TTC » — d'où les 18 % de la dernière
          colonne du détail, repris à l'identique. Placé dans un tableau
          distinct et non dans un `tfoot`, que les navigateurs répètent sur
          chaque page imprimée — un devis de deux pages afficherait alors deux
          fois son total. */}
      <table className="border-foreground/60 w-full table-fixed border-collapse border border-t-0 text-[9.5pt] break-inside-avoid">
        <colgroup>
          <col style={{ width: "82%" }} />
          <col style={{ width: "18%" }} />
        </colgroup>
        <tbody>
          {quote.discountCents > 0 ? (
            <>
              <TotalRow
                label={t("print.totals.gross")}
                value={formatAmount(
                  quote.subtotalCents + quote.discountCents,
                  locale,
                )}
              />
              <TotalRow
                label={t("totals.discount")}
                value={`−${formatAmount(quote.discountCents, locale)}`}
              />
            </>
          ) : null}

          <TotalRow
            label={t("print.totals.excludingTax")}
            value={formatAmount(quote.subtotalCents, locale)}
          />

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
              value={formatAmount(quote.taxCents, locale)}
            />
          )}

          <TotalRow
            label={t("print.totals.includingTax")}
            value={formatAmount(quote.totalCents, locale)}
            strong
          />
        </tbody>
      </table>

      {vatNotApplicable ? (
        <p className="mt-2 font-bold">{t("print.vatNotApplicable")}</p>
      ) : null}

      {/* Montant en toutes lettres : mention obligatoire (§3). Il porte le TTC,
          le montant que le client s'engage à payer. */}
      <p className="mt-4 break-inside-avoid">
        {t("print.settled", {
          amount: amountInWords(quote.totalCents, locale, quote.currency),
        })}
      </p>

      {quote.notes ? (
        <p className="mt-3 whitespace-pre-line">{quote.notes}</p>
      ) : null}
      {quote.terms ? (
        <p className="mt-2 whitespace-pre-line">{quote.terms}</p>
      ) : null}

      <div className="mt-6 break-inside-avoid text-end">
        <p className="font-bold underline">{t("print.signature")}</p>
        {/* Espace réservé au cachet et à la signature manuscrite : c'est ce que
            le modèle laisse vide, et une signature a besoin de place. */}
        <div className="h-[26mm]" aria-hidden />
        <p className="text-muted-foreground text-[9pt]">{t("print.stamp")}</p>
      </div>
    </>
  );
}
