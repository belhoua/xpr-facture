import { z } from "zod";

/**
 * Contrat de `/api/v1/partners` — clients, fournisseurs et intermédiaires dans
 * un même répertoire, discriminés par `type`.
 *
 * `both` n'est pas un cas limite : un imprimeur à qui l'on achète des
 * fournitures et à qui l'on facture des prestations est les deux à la fois.
 *
 * `intermediary` est d'une autre nature — un RÔLE (apporteur d'affaires,
 * courtier) et non un sens de facturation. Il n'apparaît dans aucun déroulant
 * de facturation, seulement sous son propre filtre (décision du 2026-08-17,
 * miroir de `PartnerType` côté serveur).
 */
export const partnerTypeSchema = z.enum([
  "client",
  "supplier",
  "both",
  "intermediary",
]);

/**
 * Les types PROPOSABLES à la saisie et au filtre, dans l'ordre d'affichage.
 * Dérivé de l'énumération plutôt que réécrit : deux listes finissent toujours
 * par diverger, et c'est le jour où l'on ajoute un type qu'on s'en aperçoit.
 */
export const PARTNER_TYPES = partnerTypeSchema.options;

export const legalFormSchema = z.enum([
  "auto_entrepreneur",
  "sarl",
  "sarl_au",
  "sa",
  "sas",
  "snc",
  "cooperative",
]);

export const partnerSchema = z.object({
  id: z.uuid(),
  type: partnerTypeSchema,
  code: z.string().nullable(),
  legalName: z.string(),
  tradeName: z.string().nullable(),
  displayName: z.string(),
  legalForm: legalFormSchema.nullable(),
  ice: z.string().nullable(),
  ifNumber: z.string().nullable(),
  rcNumber: z.string().nullable(),
  rcCity: z.string().nullable(),
  patente: z.string().nullable(),
  contactName: z.string().nullable(),
  email: z.string().nullable(),
  phone: z.string().nullable(),
  address: z.string().nullable(),
  city: z.string().nullable(),
  country: z.string().length(2),
  currency: z.string().length(3),
  paymentTermsDays: z.int(),
  notes: z.string().nullable(),
  isActive: z.boolean(),
  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export const partnerListSchema = z.object({
  data: z.array(partnerSchema),
  meta: z.object({
    total: z.int(),
    page: z.int(),
    perPage: z.int(),
  }),
});

/**
 * Validation du formulaire. Miroir de PartnerStoreRequest côté Laravel — le
 * serveur reste l'autorité (§10), ceci évite juste un aller-retour.
 *
 * L'ICE marocain fait 15 chiffres, mais reste facultatif : un particulier n'en
 * a pas, et une fiche doit pouvoir être créée avant d'avoir obtenu la pièce.
 */
export const partnerFormSchema = z.object({
  type: partnerTypeSchema,
  legalName: z.string().min(1).max(255),
  tradeName: z.string().max(255).optional(),
  ice: z
    .string()
    .regex(/^[0-9]{15}$/)
    .optional()
    .or(z.literal("")),
  ifNumber: z.string().max(20).optional(),
  contactName: z.string().max(255).optional(),
  email: z.email().optional().or(z.literal("")),
  phone: z.string().max(30).optional(),
  city: z.string().max(100).optional(),
  address: z.string().max(1000).optional(),
  paymentTermsDays: z.coerce.number<number>().int().min(0).max(365),
  notes: z.string().max(5000).optional(),
});

export type PartnerType = z.infer<typeof partnerTypeSchema>;
export type Partner = z.infer<typeof partnerSchema>;
export type PartnerList = z.infer<typeof partnerListSchema>;
export type PartnerFormValues = z.infer<typeof partnerFormSchema>;
