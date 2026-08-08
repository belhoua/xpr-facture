"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Printer } from "lucide-react";
import { useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useMe } from "@/features/auth/hooks/use-auth";
import { depositKeys, fetchDeposit } from "@/features/conventions/api/conventions";
import type { FileDeposit } from "@/features/conventions/schemas/convention";
import {
  LegalFooter,
  Letterhead,
} from "@/features/documents/components/letterhead";
import { FilledField } from "@/features/documents/components/print-primitives";
import { toApiProblem } from "@/lib/api/client";
import { BRAND } from "@/lib/brand";
import { formatDate } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Fiche de dépôt de dossier, aux couleurs de BCAT.
 *
 * Ce n'est PAS le récépissé de l'organisme — celui-là est délivré au guichet et
 * BCAT n'a pas qualité pour l'émettre. C'est la fiche de suivi que le bureau
 * remet au maître d'ouvrage : ce qui a été déposé, où, quand, et où en est
 * l'instruction. La distinction est écrite sur la feuille elle-même, pour que
 * personne ne la présente pour ce qu'elle n'est pas.
 *
 * Elle tient sur un demi-A4 et reprend le même papier à en-tête que les devis :
 * un client doit reconnaître d'un coup d'œil que la pièce vient de chez BCAT.
 */
export function DepositPrintView({ id }: { id: string }) {
  const t = useTranslations("deposits.print");

  const depositQuery = useQuery({
    queryKey: depositKeys.detail(id),
    queryFn: () => fetchDeposit(id),
  });

  const meQuery = useMe();

  if (depositQuery.isPending || meQuery.isPending) {
    return <Skeleton className="mx-auto h-[20rem] w-full max-w-[210mm]" />;
  }

  if (depositQuery.isError || meQuery.isError) {
    const error = depositQuery.error ?? meQuery.error;

    return (
      <ErrorState
        detail={toApiProblem(error).detail}
        onRetry={() => {
          void depositQuery.refetch();
          void meQuery.refetch();
        }}
      />
    );
  }

  const deposit = depositQuery.data;
  const company = meQuery.data.active_company;

  if (company === null) {
    return <ErrorState detail={t("noCompany")} />;
  }

  return (
    <div className="print-document">
      <div className="mx-auto mb-4 flex w-full max-w-[210mm] items-center gap-2 print:hidden">
        <Button variant="outline" asChild>
          <Link href="/deposits">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
            {t("back")}
          </Link>
        </Button>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          {t("action")}
        </Button>
      </div>

      <article className="print-sheet bg-card ring-border mx-auto w-full max-w-[210mm] p-[14mm] text-[11pt] leading-snug ring-1 print:ring-0">
        <Letterhead />
        <DepositBody deposit={deposit} />
        <LegalFooter company={company} />
      </article>
    </div>
  );
}

function DepositBody({ deposit }: { deposit: FileDeposit }) {
  const t = useTranslations("deposits.print");
  const tStatus = useTranslations("deposits.status");
  // Comme le contrat : la fiche est un document français, ses dates suivent la
  // même convention typographique quelle que soit la langue de l'interface.
  const contractLocale = "fr";

  const asDate = (value: string) =>
    formatDate(value, contractLocale, {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });

  return (
    <>
      <h1 className="font-heading mt-6 text-center text-xl font-bold tracking-tight uppercase">
        {t("title")}
      </h1>
      <p className="text-muted-foreground mt-1 text-center text-[9.5pt]">
        {t("subtitle")}
      </p>

      <dl className="mt-6 space-y-2">
        <FilledField label={t("reference")}>
          <span className="amount font-bold">{deposit.reference}</span>
        </FilledField>
        <FilledField label={t("organisation")}>{deposit.organisation}</FilledField>
        <FilledField label={t("depositedAt")}>
          {asDate(deposit.depositedAt)}
        </FilledField>
        <FilledField label={t("status")}>{tStatus(deposit.status)}</FilledField>
        {/* La date de décision n'existe que sur un dossier tranché : afficher un
            filet vide sur un dossier en cours suggérerait une pièce manquante. */}
        {deposit.decidedAt !== null ? (
          <FilledField label={t("decidedAt")}>
            {asDate(deposit.decidedAt)}
          </FilledField>
        ) : null}
        <FilledField label={t("owner")}>
          {deposit.convention?.ownerName ?? "—"}
        </FilledField>
        <FilledField label={t("project")}>
          {deposit.convention?.projectDescription ?? "—"}
        </FilledField>
        {deposit.convention?.dossierNumber ? (
          <FilledField label={t("dossier")}>
            <span className="amount">{deposit.convention.dossierNumber}</span>
          </FilledField>
        ) : null}
      </dl>

      {deposit.notes ? (
        <div className="mt-4">
          <p className="font-bold">{t("notes")} :</p>
          <p className="whitespace-pre-line">{deposit.notes}</p>
        </div>
      ) : null}

      <p className="text-muted-foreground mt-6 text-[9pt] italic">
        {t("disclaimer")}
      </p>

      <div className="mt-6 break-inside-avoid text-end">
        <p className="font-bold underline">{t("signature")}</p>
        <p className="text-[9.5pt]">{BRAND.name}</p>
        <div className="h-[24mm]" aria-hidden />
      </div>
    </>
  );
}
