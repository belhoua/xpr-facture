"use client";

import Image from "next/image";
import { useTranslations } from "next-intl";

import type { ApiCompany } from "@/features/auth/types/auth";
import { BRAND } from "@/lib/brand";

/**
 * En-tête et pied de page d'un document commercial imprimé.
 *
 * Séparés du document lui-même parce qu'ils n'en dépendent pas : devis,
 * facture et situation portent le MÊME papier à en-tête, et les mentions
 * légales du pied (ICE, IF, RC, CNSS, patente) sont obligatoires sur chacun
 * (§3).
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
function joinParts(
  parts: readonly (string | null | undefined)[],
  separator = "  ·  ",
): string {
  return parts.filter((part): part is string => Boolean(part)).join(separator);
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

/**
 * Pied de page légal, en TROIS lignes : identité et adresse, identifiants
 * fiscaux, coordonnées bancaires et de contact.
 *
 * Les valeurs restent lues sur la SOCIÉTÉ ACTIVE et ne sont écrites nulle part
 * ici. Les inscrire en dur donnerait un pied juste pour l'exploitant actuel et
 * faux pour tous les autres : le produit est multi-société (§5), et une facture
 * portant l'ICE d'une entreprise tierce n'est pas un défaut d'affichage mais un
 * faux document. Les coordonnées de l'exploitant vivent donc en base, posées
 * par le seeder et modifiables depuis les paramètres.
 *
 * Un champ absent n'imprime pas de libellé vide — un pied annonçant « ICE : »
 * sans numéro serait pire que son absence — et une ligne entièrement vide
 * disparaît au lieu de laisser un filet orphelin.
 */
export function LegalFooter({ company }: { company: ApiCompany }) {
  const t = useTranslations("documents.print.legal");

  // Ligne 1 — qui émet, et où. Le nom affiché est celui de la marque ; la forme
  // juridique reste celle de la fiche, elle fait partie des mentions
  // obligatoires.
  const identity = joinParts(
    [
      `${BRAND.name}${company.legal_form ? ` ${company.legal_form.toUpperCase()}` : ""}`,
      // Le code postal n'a pas de colonne propre : il vit dans `city`
      // (« OUJDA 60000 »), comme sur les en-têtes marocains courants.
      joinParts([company.address, company.city], " "),
    ],
    " — ",
  );

  // Ligne 2 — les identifiants que l'administration exige sur toute pièce
  // commerciale (§3). L'ordre suit celui du modèle de l'exploitant.
  const legalIds = joinParts([
    company.ice ? t("ice", { value: company.ice }) : null,
    company.if_number ? t("if", { value: company.if_number }) : null,
    company.rc_number
      ? t("rc", {
          value: company.rc_city
            ? `${company.rc_number} — ${company.rc_city}`
            : company.rc_number,
        })
      : null,
    company.cnss ? t("cnss", { value: company.cnss }) : null,
    company.patente ? t("patente", { value: company.patente }) : null,
  ]);

  // Ligne 3 — par où l'on paie et par où l'on joint. Le RIB en tête : c'est
  // l'information qu'un client cherche sur une facture.
  const banking = joinParts([
    company.bank_rib ? t("rib", { value: company.bank_rib }) : null,
    company.phone ? t("phone", { value: company.phone }) : null,
    company.email ? t("email", { value: company.email }) : null,
    company.website,
  ]);

  return (
    <footer className="print-legal-footer border-foreground/60 mt-6 break-inside-avoid border-t pt-2 text-center text-[8.5pt] leading-relaxed">
      {identity ? <p className="font-bold">{identity}</p> : null}
      {legalIds ? <p>{legalIds}</p> : null}
      {/* `.amount` isole le sens de lecture : un RIB de 24 chiffres suivi d'un
          numéro de téléphone se réordonnerait en arabe sans cette isolation. */}
      {banking ? <p className="amount">{banking}</p> : null}
    </footer>
  );
}
