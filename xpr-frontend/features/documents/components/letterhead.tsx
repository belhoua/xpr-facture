"use client";

import Image from "next/image";
import { useTranslations } from "next-intl";

import type { ApiCompany } from "@/features/auth/types/auth";
import { BRAND } from "@/lib/brand";

/**
 * En-tête et pied de page d'un document commercial imprimé.
 *
 * Séparés du document lui-même parce qu'ils n'en dépendent pas : devis,
 * facture et avoir portent le MÊME papier à en-tête, et les mentions légales du
 * pied (ICE, IF, RC, CNSS, patente) sont obligatoires sur chacun (§3).
 *
 * Deux sources, délibérément :
 *  - la MARQUE (logo, nom, baseline) vient de `BRAND`, fixée en dur sur
 *    décision produit — voir `lib/brand.ts` ;
 *  - les MENTIONS LÉGALES viennent de la société active. Elles doivent rester
 *    lues en base : un ICE ou un RIB erroné sur un document commercial est une
 *    faute, pas un détail de présentation. Un champ absent n'imprime pas de
 *    libellé vide — un pied annonçant « ICE : » sans numéro serait pire que son
 *    absence.
 */

/** Assemble les fragments non vides d'une ligne de mentions. */
function joinParts(parts: readonly (string | null | undefined)[]): string {
  return parts.filter((part): part is string => Boolean(part)).join("  ·  ");
}

export function Letterhead() {
  return (
    <header className="border-foreground/60 break-inside-avoid border-b-2 pb-3 text-center">
      {/* Hauteur en millimètres et non en pixels : c'est la mesure du papier,
          et elle reste la même quelle que soit la résolution d'impression.

          14 mm et non 20 : le logo est une bande de ratio ≈ 3,6 : 1, donc 14 mm
          de haut en font déjà 50 mm de large — le quart d'une A4. Le régler à
          20 mm en occuperait 72 et écraserait le reste de l'en-tête.

          `priority` est indispensable ici : une image en chargement paresseux
          peut ne pas être décodée au moment où le navigateur compose la page à
          imprimer, et le devis sortirait sans logo. */}
      {/* TOUJOURS la variante claire, jamais la bascule de thème du chrome :
          le papier est blanc. Un utilisateur en thème sombre imprimerait
          autrement un bloc noir en tête de chaque devis. */}
      <Image
        src={BRAND.logo.light.src}
        alt={BRAND.name}
        width={BRAND.logo.light.width}
        height={BRAND.logo.light.height}
        priority
        className="mx-auto h-[14mm] w-auto object-contain"
      />
      <p className="mt-1 text-lg font-bold italic">{BRAND.tagline}</p>
    </header>
  );
}

export function LegalFooter({ company }: { company: ApiCompany }) {
  const t = useTranslations("documents.print.legal");

  const identity = joinParts([
    // Le nom affiché est celui de la marque ; la forme juridique de la société
    // reste celle de la fiche, elle fait partie des mentions obligatoires.
    `${BRAND.name}${company.legal_form ? ` ${company.legal_form.toUpperCase()}` : ""}`,
    company.if_number ? t("if", { value: company.if_number }) : null,
    company.cnss ? t("cnss", { value: company.cnss }) : null,
    company.rc_number
      ? t("rc", {
          value: company.rc_city
            ? `${company.rc_number} — ${company.rc_city}`
            : company.rc_number,
        })
      : null,
    company.ice ? t("ice", { value: company.ice }) : null,
    company.patente ? t("patente", { value: company.patente }) : null,
  ]);

  const address = joinParts([company.address, company.city]);

  const contact = joinParts([
    company.phone ? t("phone", { value: company.phone }) : null,
    company.email ? t("email", { value: company.email }) : null,
    company.website,
  ]);

  return (
    <footer className="border-foreground/60 mt-6 break-inside-avoid border-t pt-2 text-center text-[10px] leading-relaxed">
      <p>{identity}</p>
      {address ? <p>{t("address", { value: address })}</p> : null}
      {company.bank_rib ? (
        <p className="amount">{t("rib", { value: company.bank_rib })}</p>
      ) : null}
      {contact ? <p>{contact}</p> : null}
    </footer>
  );
}
