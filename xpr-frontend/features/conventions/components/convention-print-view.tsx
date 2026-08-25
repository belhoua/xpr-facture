"use client";

import { useQuery } from "@tanstack/react-query";
import { ArrowLeft, Printer, SlidersHorizontal } from "lucide-react";
import { useState } from "react";
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
import { ConventionPrintPanel } from "@/features/conventions/components/convention-print-panel";
import {
  CONTRACT_ARTICLES,
  CONTRACT_FEES,
  CONTRACT_HEADINGS,
  CONTRACT_PARTY_LABELS,
  CONTRACT_SIGNATURES,
} from "@/features/conventions/contract-text";
import {
  toLotList,
  toPrintDraft,
  type ConventionPrintDraft,
} from "@/features/conventions/print-draft";
import type { Convention } from "@/features/conventions/schemas/convention";
import { EditableSheet } from "@/features/documents/components/editable-sheet";
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
 *
 * ── Personnalisation avant impression ──────────────────────────────────────
 *
 * Deux façons de retoucher le tirage, qui répondent à deux besoins distincts :
 *
 *  1. le PANNEAU (`ConventionPrintPanel`), pour ce que le contrat porte comme
 *     donnée — n° de dossier, ville et date, identité du maître d'ouvrage,
 *     projet, lots de l'article 1, clauses particulières. Le document se
 *     recompose à la frappe : c'est l'aperçu. Le brouillon manipulé est
 *     `ConventionPrintDraft`, volatile, jamais envoyé à l'API ;
 *  2. l'ÉDITION LIBRE au clavier, comme sur les factures et les devis : la
 *     feuille est un `contentEditable`, on clique dans n'importe quel texte —
 *     y compris le corps des articles — et on corrige.
 *
 * Les deux ne cohabitent pas sans arbitrage. `EditableSheet` fige sa
 * sous-arborescence pour que React n'efface pas la frappe en cours (voir son
 * fichier) : un changement du panneau doit donc la REMONTER, via `key`, ce qui
 * emporte les retouches tapées à la main. C'est le seul ordre d'usage qui tient,
 * et l'interface l'annonce : on règle d'abord les champs, on retouche le texte
 * ensuite, on imprime.
 *
 * Aucun `useEffect` n'ouvre la boîte d'impression au montage : une page qui
 * s'imprime toute seule est ingérable quand on voulait seulement la relire.
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
    <PrintableConvention
      // Le brouillon naît de la convention chargée. Changer de convention sans
      // remonter le composant garderait le brouillon de la précédente à
      // l'écran ; la `key` rend cette confusion impossible.
      key={convention.id}
      convention={convention}
      company={company}
    />
  );
}

/**
 * Le document et ses commandes, une fois les données sûres.
 *
 * Séparé de la vue précédente parce que le brouillon a besoin d'une convention
 * DÉJÀ chargée pour exister : `useState(toPrintDraft(convention))` ne peut pas
 * s'écrire au-dessus des gardes de chargement sans que les hooks changent
 * d'ordre d'un rendu à l'autre.
 */
function PrintableConvention({
  convention,
  company,
}: {
  convention: Convention;
  company: ApiCompany;
}) {
  const t = useTranslations("conventions.print");

  const [draft, setDraft] = useState<ConventionPrintDraft>(() =>
    toPrintDraft(convention),
  );
  const [panelOpen, setPanelOpen] = useState(false);

  /**
   * Compteur de remontage de la feuille. `EditableSheet` ne se reconcilie
   * jamais : sans cette `key`, une retouche du panneau ne se verrait pas.
   */
  const [revision, setRevision] = useState(0);

  const patch = (values: Partial<ConventionPrintDraft>) => {
    setDraft((current) => ({ ...current, ...values }));
    setRevision((current) => current + 1);
  };

  const reset = () => {
    setDraft(toPrintDraft(convention));
    setRevision((current) => current + 1);
  };

  return (
    // `print-contract` porte la page nommée du contrat et ses règles de
    // fragmentation : marges A4 propres, articles insécables, et minimum de
    // trois lignes de part et d'autre d'une coupure sur ceux qui dépassent la
    // page. Détail dans `globals.css`, sous `@page contract`.
    <div className="print-document print-contract">
      <div className="no-print mx-auto mb-4 flex w-full max-w-[210mm] flex-wrap items-center gap-2 print:hidden">
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
        {/* `aria-expanded` dit l'état au lecteur d'écran ET le montre : le
            variant `outline` porte déjà son propre style ouvert. */}
        <Button
          variant="outline"
          aria-expanded={panelOpen}
          onClick={() => setPanelOpen((open) => !open)}
        >
          <SlidersHorizontal className="size-4" aria-hidden />
          {t("customize")}
        </Button>

        {/* Dit une fois, sobrement : la page se corrige au clavier, et rien de
            ce qu'on y tape n'est enregistré. Sans cette phrase, un utilisateur
            qui retouche son contrat peut croire l'avoir corrigé en base. */}
        <p className="text-muted-foreground text-sm">{t("editHint")}</p>

        {/* Un contrat sans n° de dossier n'a pas encore été déposé : il
            s'imprime — on le fait signer avant le dépôt — mais on le dit. Le
            brouillon fait foi ici, pas la convention : le numéro arrivé par
            téléphone se saisit dans le panneau. */}
        {draft.dossierNumber.trim() === "" ? (
          <p className="text-muted-foreground text-sm">{t("noDossierNotice")}</p>
        ) : null}
      </div>

      {panelOpen ? (
        <ConventionPrintPanel
          draft={draft}
          onChange={patch}
          onReset={reset}
          onClose={() => setPanelOpen(false)}
        />
      ) : null}

      <EditableSheet key={revision}>
        <Letterhead />
        <ConventionBody
          draft={draft}
          convention={convention}
          company={company}
        />
        <LegalFooter company={company} />
      </EditableSheet>
    </div>
  );
}

/**
 * Corps de l'acte.
 *
 * Il lit le BROUILLON pour tout ce qui se rédige, et la CONVENTION pour ce qui
 * se calcule — le forfait, les pourcentages et l'échéancier arrondi par le
 * serveur, que le panneau ne touche pas (cf. `ConventionPrintDraft`).
 */
function ConventionBody({
  draft,
  convention,
  company,
}: {
  draft: ConventionPrintDraft;
  convention: Convention;
  company: ApiCompany;
}) {
  const t = useTranslations("conventions.print");

  // Le contrat est un acte en FRANÇAIS : ses dates et ses montants suivent la
  // même convention typographique, y compris quand l'interface est en arabe.
  // Mélanger un corps français et des chiffres en numération arabe-indienne
  // rendrait le document incohérent aux yeux du signataire.
  const contractLocale = "fr";

  const issuedOn = draft.issuedAt
    ? formatDate(draft.issuedAt, contractLocale, {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
      })
    : "—";

  const lots = toLotList(draft.lots);

  return (
    <>
      {/* « Marrakech, le 22/07/2026 ». La ville est celle SAISIE SUR L'ACTE : un
          bureau de contrôle établit ses conventions là où se trouve le chantier,
          pas à son siège. À défaut, le repli de `BRAND`. */}
      <p className="mt-4 text-end font-bold">
        {draft.issueCity.trim() || BRAND.defaultCity}, le {issuedOn}
      </p>

      <header className="mt-6 text-center">
        <h1 className="font-heading text-xl font-bold tracking-tight">
          {CONTRACT_HEADINGS.title}
        </h1>
        <p className="mt-1 font-bold">
          {CONTRACT_HEADINGS.dossier}{" "}
          <span className="amount">
            {draft.dossierNumber.trim() || "…………………………"}
          </span>
        </p>
      </header>

      <section className="mt-6 space-y-2">
        <p>
          <span className="font-bold">{CONTRACT_HEADINGS.project}</span>{" "}
          {draft.projectDescription}
          {draft.projectAddress.trim() ? `, sis à : ${draft.projectAddress}` : ""}
          {draft.projectTitleDeed.trim()
            ? ` — ${CONTRACT_HEADINGS.titleDeed} ${draft.projectTitleDeed}`
            : ""}
        </p>
        <p>
          <span className="font-bold">{CONTRACT_HEADINGS.owner}</span>{" "}
          {draft.ownerName}
        </p>
      </section>

      {/* Préambule : les deux parties, chacune suivie de sa formule. */}
      <section className="mt-6 space-y-1 break-inside-avoid">
        <p className="font-bold">
          {draft.ownerName}
          {draft.ownerRc.trim() ? `, RC ${draft.ownerRc}` : ""}
          {draft.ownerIce.trim() ? `, ICE ${draft.ownerIce}` : ""}
        </p>
        {draft.ownerAddress.trim() ? (
          <p className="whitespace-pre-line">{draft.ownerAddress}</p>
        ) : null}
        {/* Mention libre du tirage : « représenté par… », « agissant en qualité
            de… ». Elle n'existe que sur ce papier. */}
        {draft.ownerNote.trim() ? (
          <p className="whitespace-pre-line">{draft.ownerNote}</p>
        ) : null}
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
          // `contract-article` nomme le bloc pour la feuille d'impression :
          // c'est lui qui reçoit le flux bloc pleine largeur, et lui seul qui
          // s'autorise à se fragmenter quand il porte une énumération plus
          // haute qu'une page.
          <section
            key={article.heading}
            className="contract-article break-inside-avoid"
          >
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
                {lots.length > 0 ? (
                  lots.map((lot) => <li key={lot}>{lot}</li>)
                ) : (
                  <li className="text-muted-foreground">{t("noLots")}</li>
                )}
              </ul>
            ) : null}

            {article.heading === "Article 9" ? (
              <p className="mt-1 ps-6">
                {draft.executionDelay.trim() || "Néant"}
              </p>
            ) : null}

            {article.heading === "Article 10" ? (
              <FeesArticle
                convention={convention}
                ownerName={draft.ownerName}
                company={company}
              />
            ) : null}
          </section>
        ))}
      </div>

      {draft.notes.trim() ? (
        <p className="mt-4 whitespace-pre-line">{draft.notes}</p>
      ) : null}

      <section className="mt-10 flex break-inside-avoid items-start justify-between gap-8">
        <div className="flex-1">
          <p className="font-bold underline">{CONTRACT_SIGNATURES.client}</p>
          <p className="mt-1">{draft.ownerName}</p>
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
 *
 * Seul le NOM du maître d'ouvrage vient du brouillon : c'est la seule donnée de
 * cet article que le panneau retouche, les montants restant ceux du serveur.
 */
function FeesArticle({
  convention,
  ownerName,
  company,
}: {
  convention: Convention;
  ownerName: string;
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

      <p>{CONTRACT_FEES.payment.replace("{owner}", ownerName)}</p>

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
