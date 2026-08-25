import type { Convention } from "@/features/conventions/schemas/convention";

/**
 * Version RETOUCHABLE d'une convention, le temps d'une impression.
 *
 * ── Pourquoi un état de plus, alors que la convention est déjà en base ─────
 *
 * Le contrat imprimé ne dit pas toujours exactement ce que la fiche enregistre :
 * on ajoute la référence du dossier qui vient d'arriver par téléphone, on
 * corrige l'orthographe d'une raison sociale, on retire un lot que le maître
 * d'ouvrage a finalement confié à un autre bureau, on vide une note interne qui
 * n'a rien à faire sur un acte signé. Ces retouches sont propres au TIRAGE, pas
 * à la convention : les écrire en base changerait un contrat déjà envoyé.
 *
 * Ce brouillon est donc VOLATILE, comme l'édition libre des factures et des
 * devis (`features/documents/components/editable-sheet.tsx`) : rien ne part vers
 * l'API, et recharger la page rétablit la convention enregistrée. Ce qui doit
 * durer se corrige dans le formulaire (`/conventions/[id]/edit`).
 *
 * Ce que le brouillon ne porte PAS, volontairement : le forfait, les
 * pourcentages et l'échéancier. `instalmentsCents` est calculé par le serveur,
 * qui fait autorité sur l'arrondi (§7) ; laisser retoucher un montant ici
 * produirait un échéancier dont les trois parts ne feraient plus le total.
 * Un honoraire qui change, c'est un avenant, pas une retouche d'impression.
 */
export interface ConventionPrintDraft {
  /** N° de dossier. Vide = les pointillés du modèle, à remplir à la main. */
  dossierNumber: string;
  issueCity: string;
  /** `yyyy-mm-dd`. Vide = aucune date imprimée. */
  issuedAt: string;

  ownerName: string;
  ownerIce: string;
  ownerRc: string;
  ownerAddress: string;
  /**
   * Mention libre sous le bloc du maître d'ouvrage (« représenté par… »,
   * « agissant en qualité de… »). Propre au tirage : la convention n'a pas de
   * colonne pour elle, et n'en a pas besoin.
   */
  ownerNote: string;

  projectDescription: string;
  projectAddress: string;
  projectTitleDeed: string;

  /**
   * Lots contrôlés de l'article 1, UN PAR LIGNE — même représentation que dans
   * le formulaire de saisie, et pour la même raison : c'est ainsi qu'on les
   * rédige et qu'on les colle depuis un précédent contrat. Une liste de champs
   * à boutons demanderait quatre gestes là où un collage suffit.
   */
  lots: string;
  executionDelay: string;

  /** Clauses particulières imprimées au-dessus des signatures. */
  notes: string;
}

/** Le brouillon de départ : la convention telle qu'elle est enregistrée. */
export function toPrintDraft(convention: Convention): ConventionPrintDraft {
  return {
    dossierNumber: convention.dossierNumber ?? "",
    issueCity: convention.issueCity ?? "",
    issuedAt: convention.issuedAt ?? "",
    ownerName: convention.ownerName,
    ownerIce: convention.ownerIce ?? "",
    ownerRc: convention.ownerRc ?? "",
    ownerAddress: convention.ownerAddress ?? "",
    ownerNote: "",
    projectDescription: convention.projectDescription,
    projectAddress: convention.projectAddress ?? "",
    projectTitleDeed: convention.projectTitleDeed ?? "",
    lots: convention.lots.join("\n"),
    executionDelay: convention.executionDelay ?? "",
    notes: convention.notes ?? "",
  };
}

/**
 * Texte multiligne → puces de l'article 1.
 *
 * Les lignes vides sont ignorées : on en laisse toujours une en fin de saisie,
 * et elle imprimerait une puce nue au milieu de l'acte.
 */
export function toLotList(lots: string): string[] {
  return lots
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line !== "");
}
