"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Printer } from "lucide-react";
import { useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useMe } from "@/features/auth/hooks/use-auth";
import type { ApiCompany } from "@/features/auth/types/auth";
import {
  conventionKeys,
  fetchConvention,
} from "@/features/conventions/api/conventions";
import {
  CONTRACT_ARTICLES,
  CONTRACT_FEES,
  CONTRACT_HEADINGS,
  CONTRACT_PARTY_LABELS,
  CONTRACT_SIGNATURES,
} from "@/features/conventions/contract-text";
import type { Convention } from "@/features/conventions/schemas/convention";
import {
  LegalFooter,
  Letterhead,
} from "@/features/documents/components/letterhead";
import { amountInWords } from "@/lib/amount-in-words";
import { toApiProblem } from "@/lib/api/client";
import { BRAND } from "@/lib/brand";
import { formatAmount, formatDate } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Contrat de convention prêt à imprimer, calqué sur le modèle Word fourni par le
 * client (`docs/Contrat de convention modele.docx`) : papier à en-tête BCAT,
 * préambule des deux parties, articles 1 à 10, échéancier chiffré, bloc de
 * signature.
 *
 * Impression NAVIGATEUR et non PDF serveur, comme les devis et les situations :
 * Gotenberg (§4) entrera en jeu pour le document archivé et envoyé par e-mail,
 * où la fidélité du rendu doit être garantie hors de tout navigateur. Tant que
 * cette chaîne n'existe pas, une page imprimable rend le même service sans faire
 * dépendre l'impression d'un conteneur.
 *
 * Le CORPS de l'acte est en français, quelle que soit la langue de l'interface :
 * c'est un texte contractuel, et `features/conventions/contract-text.ts` dit
 * pourquoi il n'est pas traduit à la volée.
 */
export function ConventionPrintView({ id }: { id: string }) {
  const t = useTranslations("conventions.print");

  const conventionQuery = useQuery({
    queryKey: conventionKeys.detail(id),
    queryFn: () => fetchConvention(id),
  });

  // Les mentions légales de l'émetteur viennent de la société active, jamais
  // d'une constante : un RC ou un RIB erroné sur un acte signé est une faute.
  const meQuery = useMe();

  if (conventionQuery.isPending || meQuery.isPending) {
    return <Skeleton className="mx-auto h-[26rem] w-full max-w-[210mm]" />;
  }

  if (conventionQuery.isError || meQuery.isError) {
    const error = conventionQuery.error ?? meQuery.error;

    return (
      <ErrorState
        detail={toApiProblem(error).detail}
        onRetry={() => {
          void conventionQuery.refetch();
          void meQuery.refetch();
        }}
      />
    );
  }

  const convention = conventionQuery.data;
  const company = meQuery.data.active_company;

  if (company === null) {
    return <ErrorState detail={t("noCompany")} />;
  }

  return (
    <div className="print-document">
      <div className="mx-auto mb-4 flex w-full max-w-[210mm] items-center gap-2 print:hidden">
        <Button variant="outline" asChild>
          <Link href="/conventions">
            <ArrowLeft className="size-4 rtl:rotate-180" aria-hidden />
            {t("back")}
          </Link>
        </Button>
        <Button onClick={() => window.print()}>
          <Printer className="size-4" aria-hidden />
          {t("action")}
        </Button>

        {/* Un contrat sans n° de dossier n'a pas encore été déposé : il
            s'imprime — on le fait signer avant le dépôt — mais on le dit. */}
        {convention.dossierNumber === null ? (
          <p className="text-muted-foreground text-sm">{t("noDossierNotice")}</p>
        ) : null}
      </div>

      <article className="print-sheet bg-card ring-border mx-auto w-full max-w-[210mm] p-[14mm] text-[11pt] leading-snug ring-1 print:ring-0">
        <Letterhead />
        <ConventionBody convention={convention} company={company} />
        <LegalFooter company={company} />
      </article>
    </div>
  );
}

function ConventionBody({
  convention,
  company,
}: {
  convention: Convention;
  company: ApiCompany;
}) {
  const t = useTranslations("conventions.print");

  // Le contrat est un acte en FRANÇAIS : ses dates et ses montants suivent la
  // même convention typographique, y compris quand l'interface est en arabe.
  // Mélanger un corps français et des chiffres en numération arabe-indienne
  // rendrait le document incohérent aux yeux du signataire.
  const contractLocale = "fr";

  const issuedOn = convention.issuedAt
    ? formatDate(convention.issuedAt, contractLocale, {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      })
    : "—";

  return (
    <>
      {/* « Marrakech, le 22/07/2026 ». La ville est celle SAISIE SUR L'ACTE : un
          bureau de contrôle établit ses conventions là où se trouve le chantier,
          pas à son siège. À défaut, le repli de `BRAND`. */}
      <p className="mt-4 text-end font-bold">
        {convention.issueCity ?? BRAND.defaultCity}, le {issuedOn}
      </p>

      <header className="mt-6 text-center">
        <h1 className="font-heading text-xl font-bold tracking-tight">
          {CONTRACT_HEADINGS.title}
        </h1>
        <p className="mt-1 font-bold">
          {CONTRACT_HEADINGS.dossier}{" "}
          <span className="amount">
            {convention.dossierNumber ?? "…………………………"}
          </span>
        </p>
      </header>

      <section className="mt-6 space-y-2">
        <p>
          <span className="font-bold">{CONTRACT_HEADINGS.project}</span>{" "}
          {convention.projectDescription}
          {convention.projectAddress ? `, sis à : ${convention.projectAddress}` : ""}
          {convention.projectTitleDeed
            ? ` — ${CONTRACT_HEADINGS.titleDeed} ${convention.projectTitleDeed}`
            : ""}
        </p>
        <p>
          <span className="font-bold">{CONTRACT_HEADINGS.owner}</span>{" "}
          {convention.ownerName}
        </p>
      </section>

      {/* Préambule : les deux parties, chacune suivie de sa formule. */}
      <section className="mt-6 space-y-1 break-inside-avoid">
        <p className="font-bold">
          {convention.ownerName}
          {convention.ownerRc ? `, RC ${convention.ownerRc}` : ""}
          {convention.ownerIce ? `, ICE ${convention.ownerIce}` : ""}
        </p>
        {convention.ownerAddress ? <p>{convention.ownerAddress}</p> : null}
        <p className="text-end italic">{CONTRACT_PARTY_LABELS.firstParty}</p>
      </section>

      <p className="mt-3 font-bold">{CONTRACT_HEADINGS.between}</p>

      <section className="mt-2 space-y-1 break-inside-avoid">
        <p>
          {CONTRACT_HEADINGS.representative} {BRAND.representative}
        </p>
        <p>
          {CONTRACT_PARTY_LABELS.actingFor} {BRAND.name}
          {company.legal_form ? ` ${company.legal_form.toUpperCase()}` : ""}
        </p>
        {/* Chaque mention légale est OMISE si la société ne la porte pas : une
            ligne « Patente n° » sans numéro vaudrait moins que son absence. */}
        {company.rc_number ? (
          <p>
            {CONTRACT_PARTY_LABELS.registeredAt}{" "}
            {company.rc_city ?? company.city ?? "—"} sous n° {company.rc_number}
          </p>
        ) : null}
        {company.cnss ? (
          <p>
            {CONTRACT_PARTY_LABELS.cnss} {company.cnss}
          </p>
        ) : null}
        {company.patente ? (
          <p>
            {CONTRACT_PARTY_LABELS.patente} {company.patente}
          </p>
        ) : null}
        {company.bank_rib ? (
          <p>
            {CONTRACT_PARTY_LABELS.account}{" "}
            <span className="amount">RIB {company.bank_rib}</span>
          </p>
        ) : null}
        {company.address ? (
          <p>
            {CONTRACT_PARTY_LABELS.domicile} {company.address}
            {company.city ? ` ${company.city}` : ""}
          </p>
        ) : null}
        <p>{CONTRACT_PARTY_LABELS.designation}</p>
        <p className="text-end italic">{CONTRACT_PARTY_LABELS.secondParty}</p>
      </section>

      <div className="mt-6 space-y-4">
        {CONTRACT_ARTICLES.map((article) => (
          <section key={article.heading} className="break-inside-avoid">
            <p>
              <span className="font-bold underline">{article.heading}</span>
              {article.body ? ` : ${article.body}` : ""}
            </p>

            {article.items ? (
              <ul className="mt-1 list-disc space-y-0.5 ps-6">
                {article.items.map((item) => (
                  <li key={item}>{item}</li>
                ))}
              </ul>
            ) : null}

            {/* Trois articles sont COMPOSÉS depuis la convention : les lots
                contrôlés (1), le délai d'exécution (9) et les honoraires (10).
                C'est exactement ce que l'application apporte face à un modèle
                Word rempli à la main. */}
            {article.heading === "Article 1" ? (
              <ul className="mt-1 list-disc space-y-0.5 ps-6">
                {convention.lots.length > 0 ? (
                  convention.lots.map((lot) => <li key={lot}>{lot}</li>)
                ) : (
                  <li className="text-muted-foreground">{t("noLots")}</li>
                )}
              </ul>
            ) : null}

            {article.heading === "Article 9" ? (
              <p className="mt-1 ps-6">{convention.executionDelay ?? "Néant"}</p>
            ) : null}

            {article.heading === "Article 10" ? (
              <FeesArticle convention={convention} company={company} />
            ) : null}
          </section>
        ))}
      </div>

      {convention.notes ? (
        <p className="mt-4 whitespace-pre-line">{convention.notes}</p>
      ) : null}

      <section className="mt-10 flex break-inside-avoid items-start justify-between gap-8">
        <div className="flex-1">
          <p className="font-bold underline">{CONTRACT_SIGNATURES.client}</p>
          <p className="mt-1">{convention.ownerName}</p>
          {/* Espace réservé au cachet et à la signature manuscrite : c'est ce
              que le modèle laisse vide, et une signature a besoin de place. */}
          <div className="h-[28mm]" aria-hidden />
        </div>
        <div className="flex-1">
          <p className="font-bold underline">{CONTRACT_SIGNATURES.provider}</p>
          <p className="mt-1">{BRAND.name}</p>
          <div className="h-[28mm]" aria-hidden />
        </div>
      </section>
    </>
  );
}

/**
 * Article 10 : le forfait, son montant en toutes lettres, l'échéancier et le
 * compte de règlement.
 *
 * Le montant EN LETTRES est une mention de forme du modèle client, et c'est
 * aussi ce qui prévaut sur les chiffres en cas de litige. Il porte le TTC — la
 * somme que le maître d'ouvrage s'engage à verser.
 */
function FeesArticle({
  convention,
  company,
}: {
  convention: Convention;
  company: ApiCompany;
}) {
  const contractLocale = "fr";
  const instalments = convention.instalmentsCents;

  const schedule = [
    { text: CONTRACT_FEES.advance, percent: convention.advancePercent, cents: instalments.advance },
    { text: CONTRACT_FEES.visa, percent: convention.visaPercent, cents: instalments.visa },
    {
      text: CONTRACT_FEES.completion,
      percent: convention.completionPercent,
      cents: instalments.completion,
    },
  ];

  return (
    <div className="mt-1 space-y-2">
      <p>
        {CONTRACT_FEES.intro}{" "}
        <span className="amount font-bold">
          {formatAmount(convention.totalCents, contractLocale)}{" "}
          {convention.currency === "MAD" ? "DH" : convention.currency} TTC
        </span>{" "}
        (
        {amountInWords(convention.totalCents, contractLocale, convention.currency)}{" "}
        toutes taxes comprises) {CONTRACT_FEES.detail}
      </p>

      <ul className="list-disc space-y-0.5 ps-6">
        {schedule.map((line) => (
          <li key={line.text}>
            {line.text.replace("{percent}", String(line.percent))}{" "}
            <span className="amount">
              ({formatAmount(line.cents, contractLocale)}{" "}
              {convention.currency === "MAD" ? "DH" : convention.currency})
            </span>
          </li>
        ))}
      </ul>

      <p>{CONTRACT_FEES.payment.replace("{owner}", convention.ownerName)}</p>

      <dl className="ps-6">
        <div className="flex gap-2">
          <dt className="font-bold">{CONTRACT_FEES.bank}</dt>
          <dd>{BRAND.bankName}</dd>
        </div>
        {company.bank_rib ? (
          <div className="flex gap-2">
            <dt className="font-bold">{CONTRACT_FEES.account}</dt>
            <dd className="amount">RIB {company.bank_rib}</dd>
          </div>
        ) : null}
      </dl>
    </div>
  );
}
