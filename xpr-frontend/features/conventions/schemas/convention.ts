import { z } from "zod";

/**
 * Contrat de convention de contrôle et suivi — miroir de `ConventionResource`.
 *
 * La convention n'est PAS un document commercial : elle a sa table, ses
 * endpoints et son schéma. Ce que la partager avec `documentSchema` aurait
 * coûté est décrit côté serveur, dans la migration `create_conventions_table` —
 * en résumé : ni lignes, ni TVA, ni numérotation fiscale, mais un maître
 * d'ouvrage, un titre foncier et un échéancier en pourcentages.
 */

/** Miroir de `Conventions\Enums\ConventionStatus`. */
export const CONVENTION_STATUSES = ["draft", "sent", "signed", "cancelled"] as const;

export type ConventionStatus = (typeof CONVENTION_STATUSES)[number];

/** Miroir de `Conventions\Enums\DepositStatus`. */
export const DEPOSIT_STATUSES = [
  "deposited",
  "in_progress",
  "validated",
  "rejected",
] as const;

export type DepositStatus = (typeof DEPOSIT_STATUSES)[number];

/** Contexte minimal du projet, rendu avec un dépôt de la liste transverse. */
const depositConventionSchema = z.object({
  id: z.uuid(),
  ownerName: z.string(),
  projectDescription: z.string(),
  dossierNumber: z.string().nullable(),
});

export const fileDepositSchema = z.object({
  id: z.uuid(),
  conventionId: z.uuid(),
  reference: z.string(),
  depositedAt: z.iso.date(),
  organisation: z.string(),
  status: z.enum(DEPOSIT_STATUSES),
  /** Renseignée seulement sur un dossier tranché (validé / rejeté). */
  decidedAt: z.iso.date().nullable(),
  notes: z.string().nullable(),
  /**
   * Absent quand la relation n'est pas chargée, `null` quand la convention a
   * été archivée — le dépôt survit à son contexte, et l'écran doit pouvoir le
   * dire plutôt que planter.
   */
  convention: depositConventionSchema.nullable().optional(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const conventionSchema = z.object({
  id: z.uuid(),
  /** Devis ou facture d'origine, quand la convention vient d'un transfert. */
  sourceDocumentId: z.uuid().nullable(),
  sourceDocumentNumber: z.string().nullable().optional(),
  partnerId: z.uuid().nullable(),

  /** N° de dossier délivré par l'organisme : saisi, jamais généré. */
  dossierNumber: z.string().nullable(),
  status: z.enum(CONVENTION_STATUSES),
  issueCity: z.string().nullable(),
  issuedAt: z.iso.date().nullable(),

  ownerName: z.string(),
  ownerIce: z.string().nullable(),
  ownerRc: z.string().nullable(),
  ownerAddress: z.string().nullable(),

  projectDescription: z.string(),
  projectAddress: z.string().nullable(),
  /** Titre foncier (« TF : 138618/04 »). */
  projectTitleDeed: z.string().nullable(),

  /** Lots contrôlés (article 1). */
  lots: z.array(z.string()),
  executionDelay: z.string().nullable(),

  totalCents: z.int(),
  currency: z.string().length(3),
  advancePercent: z.int(),
  visaPercent: z.int(),
  completionPercent: z.int(),
  /**
   * Échéancier en centimes, CALCULÉ PAR LE SERVEUR. Le refaire ici depuis les
   * pourcentages ferait cohabiter deux arrondis, et c'est le contrat imprimé
   * qui afficherait l'écart d'un centime.
   */
  instalmentsCents: z.object({
    advance: z.int(),
    visa: z.int(),
    completion: z.int(),
  }),

  notes: z.string().nullable(),
  deposits: z.array(fileDepositSchema).optional(),

  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

const listMetaSchema = z.object({
  total: z.int().nonnegative(),
  page: z.int().positive(),
  perPage: z.int().positive(),
});

export const conventionListSchema = z.object({
  data: z.array(conventionSchema),
  meta: listMetaSchema,
});

export const fileDepositListSchema = z.object({
  data: z.array(fileDepositSchema),
  meta: listMetaSchema,
});

export type Convention = z.infer<typeof conventionSchema>;
export type ConventionList = z.infer<typeof conventionListSchema>;
export type FileDeposit = z.infer<typeof fileDepositSchema>;
export type FileDepositList = z.infer<typeof fileDepositListSchema>;

/* ------------------------------------------------------------- Formulaires */

/**
 * Saisie d'une convention.
 *
 * Les lots sont un TEXTE MULTILIGNE et non un tableau de champs : c'est ainsi
 * qu'on les rédige — quatre lignes qu'on colle depuis un précédent contrat.
 * Un éditeur de liste à boutons « ajouter / retirer » demanderait quatre gestes
 * là où un collage suffit. La conversion ligne ↔ élément a lieu à la frontière
 * de l'API, à un seul endroit.
 */
export const conventionFormSchema = z
  .object({
    /** "" = maître d'ouvrage libre, non répertorié. */
    partnerId: z.string(),
    ownerName: z
      .string()
      .trim()
      .min(2, "validation.required")
      .max(255, "validation.tooLong"),
    /** 15 chiffres, comme la contrainte CHECK en base (§3). "" = non renseigné. */
    ownerIce: z
      .string()
      .trim()
      .refine((value) => value === "" || /^[0-9]{15}$/.test(value), "validation.ice"),
    ownerRc: z.string().trim().max(40, "validation.tooLong"),
    ownerAddress: z.string().trim().max(1000, "validation.tooLong"),

    projectDescription: z
      .string()
      .trim()
      .min(3, "validation.required")
      .max(2000, "validation.tooLong"),
    projectAddress: z.string().trim().max(1000, "validation.tooLong"),
    projectTitleDeed: z.string().trim().max(60, "validation.tooLong"),

    dossierNumber: z.string().trim().max(40, "validation.tooLong"),
    status: z.enum(CONVENTION_STATUSES),
    issueCity: z.string().trim().max(100, "validation.tooLong"),
    issuedAt: z.string(),

    /** Une ligne par lot. Les lignes vides sont ignorées à l'envoi. */
    lots: z.string().trim().max(5000, "validation.tooLong"),
    executionDelay: z.string().trim().max(255, "validation.tooLong"),

    /** Forfait TTC saisi en MAD ; converti en centimes à l'envoi (§7). */
    total: z
      .number("validation.amount")
      .nonnegative("validation.amount")
      .max(99999999, "validation.amount"),
    advancePercent: z.number("validation.percent").min(0).max(100),
    visaPercent: z.number("validation.percent").min(0).max(100),
    completionPercent: z.number("validation.percent").min(0).max(100),

    notes: z.string().trim().max(5000, "validation.tooLong"),
  })
  // Miroir de la contrainte CHECK `conventions_schedule_check` et du contrôle
  // croisé du FormRequest. Le serveur reste juge — cette règle épargne un
  // aller-retour, et surtout elle affiche l'erreur SOUS le champ fautif.
  .refine(
    (values) =>
      values.advancePercent + values.visaPercent + values.completionPercent === 100,
    { path: ["advancePercent"], message: "validation.scheduleTotal" },
  );

export type ConventionFormValues = z.infer<typeof conventionFormSchema>;

export const depositFormSchema = z
  .object({
    /**
     * Convention instruite. Dans le formulaire et non déduite du contexte,
     * parce que le dépôt se saisit depuis DEUX endroits : la fiche d'une
     * convention — où le champ est masqué, il est déjà connu — et l'écran
     * transverse des dépôts, où il faut bien dire de quel dossier on parle.
     * Un seul schéma pour les deux, plutôt qu'un champ optionnel dont on ne
     * saurait jamais s'il a été renseigné.
     */
    conventionId: z.string().min(1, "validation.required"),
    reference: z
      .string()
      .trim()
      .min(2, "validation.required")
      .max(40, "validation.tooLong"),
    depositedAt: z.string().min(1, "validation.required"),
    organisation: z
      .string()
      .trim()
      .min(2, "validation.required")
      .max(255, "validation.tooLong"),
    status: z.enum(DEPOSIT_STATUSES),
    decidedAt: z.string(),
    notes: z.string().trim().max(5000, "validation.tooLong"),
  })
  // Miroir de `file_deposits_decided_after_check` : une décision ne précède pas
  // le dépôt qu'elle tranche.
  .refine(
    (values) =>
      values.decidedAt === "" ||
      values.depositedAt === "" ||
      values.decidedAt >= values.depositedAt,
    { path: ["decidedAt"], message: "validation.decidedBeforeDeposit" },
  );

export type DepositFormValues = z.infer<typeof depositFormSchema>;

/**
 * Valeurs par défaut d'une convention neuve.
 *
 * L'échéancier naît à 25 / 25 / 50 : ce sont les modalités du modèle client, et
 * de très loin le cas courant. La date du jour est pré-remplie — un contrat se
 * date du jour où on le rédige.
 */
export function emptyConvention(): ConventionFormValues {
  return {
    partnerId: "",
    ownerName: "",
    ownerIce: "",
    ownerRc: "",
    ownerAddress: "",
    projectDescription: "",
    projectAddress: "",
    projectTitleDeed: "",
    dossierNumber: "",
    status: "draft",
    issueCity: "",
    issuedAt: new Date().toISOString().slice(0, 10),
    lots: "",
    executionDelay: "",
    total: 0,
    advancePercent: 25,
    visaPercent: 25,
    completionPercent: 50,
    notes: "",
  };
}

/** Reconstitue les valeurs de saisie depuis une convention existante. */
export function toConventionFormValues(
  convention: Convention,
): ConventionFormValues {
  return {
    partnerId: convention.partnerId ?? "",
    ownerName: convention.ownerName,
    ownerIce: convention.ownerIce ?? "",
    ownerRc: convention.ownerRc ?? "",
    ownerAddress: convention.ownerAddress ?? "",
    projectDescription: convention.projectDescription,
    projectAddress: convention.projectAddress ?? "",
    projectTitleDeed: convention.projectTitleDeed ?? "",
    dossierNumber: convention.dossierNumber ?? "",
    status: convention.status,
    issueCity: convention.issueCity ?? "",
    issuedAt: convention.issuedAt ?? "",
    lots: convention.lots.join("\n"),
    executionDelay: convention.executionDelay ?? "",
    // Centimes → MAD : le formulaire manipule des montants lisibles, la
    // conversion inverse a lieu à l'envoi. Seule frontière où les deux
    // représentations se croisent.
    total: convention.totalCents / 100,
    advancePercent: convention.advancePercent,
    visaPercent: convention.visaPercent,
    completionPercent: convention.completionPercent,
    notes: convention.notes ?? "",
  };
}

/** Dépôt neuf : déposé aujourd'hui, sans décision — l'état de tout récépissé. */
export function emptyDeposit(
  conventionId: string,
  reference: string,
): DepositFormValues {
  return {
    conventionId,
    reference,
    depositedAt: new Date().toISOString().slice(0, 10),
    organisation: "",
    status: "deposited",
    decidedAt: "",
    notes: "",
  };
}

export function toDepositFormValues(deposit: FileDeposit): DepositFormValues {
  return {
    conventionId: deposit.conventionId,
    reference: deposit.reference,
    depositedAt: deposit.depositedAt,
    organisation: deposit.organisation,
    status: deposit.status,
    decidedAt: deposit.decidedAt ?? "",
    notes: deposit.notes ?? "",
  };
}

/* ------------------------------------------------------- Règles d'affichage */

/**
 * Une convention ANNULÉE ne se modifie plus : rouvrir un contrat abandonné
 * effacerait la trace de l'abandon. Miroir de `ConventionService::update()`.
 *
 * La convention SIGNÉE, elle, reste modifiable — le gel du §3 vise les pièces
 * fiscales numérotées, pas un contrat de droit privé.
 */
export function isConventionEditable(convention: Convention): boolean {
  return convention.status !== "cancelled";
}

/**
 * Une convention signée s'ANNULE, elle ne se supprime pas : l'engagement existe
 * sur le papier signé par le client, l'effacer de l'écran ne le défait pas.
 * Miroir de `ConventionService::delete()`.
 */
export function isConventionDeletable(convention: Convention): boolean {
  return convention.status !== "signed";
}
