import { z } from "zod";

import type { Document } from "@/features/documents/schemas/document";

/**
 * La SITUATION réutilise le contrat `Document` du serveur — c'est le même type
 * de ressource, discriminé par `type: "situation"`. Ce fichier n'ajoute donc
 * pas un schéma de lecture concurrent : il porte le schéma de SAISIE, qui lui
 * est propre.
 *
 * Ce que le formulaire n'a pas, et pourquoi :
 *  - **pas de lignes** — le montant est global, saisi en en-tête ;
 *  - **pas de TVA** — la situation est une pièce de suivi, pas une pièce
 *    fiscale : ni base taxable, ni récapitulatif par taux ;
 *  - **pas de TVA** — la situation est une pièce de suivi, pas une pièce
 *    fiscale : ni base taxable, ni récapitulatif par taux.
 *
 * Le STATUT, lui, est saisi : c'est le seul champ que le serveur saurait
 * déduire mais laisse à l'utilisateur, parce qu'« en cours » n'est déductible
 * d'aucun montant — rien dans les chiffres ne dit qu'un chantier est ouvert.
 */

/**
 * États proposés à la saisie, dans l'ordre du cycle. Miroir de
 * `DocumentStatus::manuallyAssignableFor(Situation)` : `draft` en est absent
 * (la situation est numérotée dès sa création) et `cancelled` aussi
 * (l'annulation a son endpoint, avec ses propres règles).
 */
export const SITUATION_STATUSES = [
  "sent",
  "in_progress",
  "partial",
  "paid",
] as const;

export type SituationStatus = (typeof SITUATION_STATUSES)[number];
export const situationFormSchema = z
  .object({
    /**
     * Client OBLIGATOIRE, contrairement aux autres documents : l'écran
     * « situations par client » agrège sur ce champ. Une situation sans
     * client y serait invisible, donc absente des totaux.
     */
    partnerId: z.string().min(1, "validation.required"),
    subject: z
      .string()
      .trim()
      .min(1, "validation.required")
      .max(255, "validation.tooLong"),
    issuedAt: z.string(),
    /** État d'avancement. Obligatoire : le formulaire ne laisse pas déduire. */
    status: z.enum(SITUATION_STATUSES),
    /** Montant global, saisi en MAD ; converti en centimes à l'envoi (§7). */
    total: z
      .number("validation.amount")
      .nonnegative("validation.amount")
      .max(99999999, "validation.amount"),
    /** Avance déjà encaissée, en MAD. */
    paid: z
      .number("validation.amount")
      .nonnegative("validation.amount")
      .max(99999999, "validation.amount"),
    notes: z.string().trim().max(5000, "validation.tooLong"),
  })
  // Miroir du contrôle croisé de `DocumentStoreRequest::withValidator()`. Le
  // serveur reste juge — cette règle épargne juste un aller-retour.
  .refine((values) => values.paid <= values.total, {
    path: ["paid"],
    message: "validation.advanceAboveTotal",
  });

export type SituationFormValues = z.infer<typeof situationFormSchema>;

/** Totaux d'un ensemble de situations. Miroir de `DocumentSummaryController`. */
export const situationSummarySchema = z.object({
  count: z.int().nonnegative(),
  totalCents: z.int(),
  paidCents: z.int(),
  remainingCents: z.int(),
});

export type SituationSummary = z.infer<typeof situationSummarySchema>;

/**
 * Valeurs par défaut du formulaire de création.
 *
 * La date du jour est pré-remplie : elle détermine l'exercice, donc la
 * séquence de numérotation. La laisser vide obligerait à la saisir à chaque
 * fois pour le cas de loin le plus courant.
 */
export function emptySituation(): SituationFormValues {
  const today = new Date().toISOString().slice(0, 10);

  return {
    partnerId: "",
    subject: "",
    issuedAt: today,
    // « Non payé » : l'état de très loin le plus fréquent à la création, et le
    // seul qui ne prétende rien — annoncer « en cours » par défaut affirmerait
    // quelque chose que l'utilisateur n'a pas dit.
    status: "sent",
    total: 0,
    paid: 0,
    notes: "",
  };
}

/** Reconstitue les valeurs de saisie depuis une situation existante. */
export function toFormValues(document: Document): SituationFormValues {
  // Une situation annulée n'est de toute façon plus modifiable (le serveur
  // répond 409) ; on retombe sur « non payé » pour que le Select ait une
  // valeur valide plutôt qu'un état vide.
  const status = SITUATION_STATUSES.find((value) => value === document.status);

  return {
    partnerId: document.partnerId ?? "",
    subject: document.subject ?? "",
    issuedAt: document.issuedAt ?? "",
    status: status ?? "sent",
    // Centimes → MAD : le formulaire manipule des montants lisibles, la
    // conversion inverse a lieu à l'envoi. Seule frontière où les deux
    // représentations se croisent.
    total: document.totalCents / 100,
    paid: document.paidCents / 100,
    notes: document.notes ?? "",
  };
}
