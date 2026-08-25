import { z } from "zod";

/**
 * Contrat de `GET /api/v1/users` et de l'invitation.
 *
 * Les rôles reprennent EXACTEMENT ceux seedés côté Spatie Permission (P0-10) :
 * owner, admin, accountant, sales, viewer. Toute divergence ici produirait une
 * invitation acceptée par le formulaire et rejetée par l'API.
 */
export const COMPANY_ROLES = [
  "owner",
  "admin",
  "accountant",
  "sales",
  "viewer",
] as const;

export const companyRoleSchema = z.enum(COMPANY_ROLES);

export const companyUserSchema = z.object({
  id: z.uuid(),
  name: z.string(),
  email: z.email(),
  role: companyRoleSchema,
  /** `invited` tant que l'invitation n'a pas été acceptée. */
  state: z.enum(["active", "invited"]),
  lastActiveAt: z.iso.datetime().nullable(),
});

export const companyUserListSchema = z.object({
  data: z.array(companyUserSchema),
});

/**
 * Invitation. `owner` est volontairement ABSENT des choix : le propriétaire
 * d'une société se transfère, il ne s'invite pas.
 */
export const inviteUserSchema = z.object({
  name: z.string().min(2, "validation.required"),
  email: z.email("validation.email"),
  role: z.enum(["admin", "accountant", "sales", "viewer"]),
});

export type CompanyUser = z.infer<typeof companyUserSchema>;
export type InviteUserValues = z.infer<typeof inviteUserSchema>;
