import { z } from "zod";

/**
 * Schémas Zod : source de vérité de la validation côté client (CLAUDE.md §6),
 * miroirs des FormRequests backend. Les messages sont des CLÉS de traduction
 * résolues à l'affichage (FieldError) — un même schéma sert FR, AR et EN.
 * Le backend reste l'autorité finale : ces règles évitent un aller-retour,
 * elles ne protègent rien.
 */

/** Doit rester aligné sur l'enum PHP LegalForm et le CHECK en base. */
export const LEGAL_FORMS = [
  "auto_entrepreneur",
  "sarl",
  "sarl_au",
  "sa",
  "sas",
  "snc",
  "cooperative",
] as const;

export type LegalForm = (typeof LEGAL_FORMS)[number];

export const loginSchema = z.object({
  email: z.string().min(1, "validation.required").email("validation.email"),
  password: z.string().min(1, "validation.required"),
  remember: z.boolean(),
});

export type LoginValues = z.infer<typeof loginSchema>;

export const registerSchema = z.object({
  name: z.string().min(1, "validation.required").max(150, "validation.tooLong"),
  email: z.string().min(1, "validation.required").email("validation.email"),
  password: z.string().min(8, "validation.passwordMin"),
  company_legal_name: z
    .string()
    .min(1, "validation.required")
    .max(255, "validation.tooLong"),
  company_legal_form: z.enum(LEGAL_FORMS, {
    message: "validation.required",
  }),
  locale: z.enum(["fr", "ar", "en"]),
});

export type RegisterValues = z.infer<typeof registerSchema>;

export const forgotPasswordSchema = z.object({
  email: z.string().min(1, "validation.required").email("validation.email"),
});

export type ForgotPasswordValues = z.infer<typeof forgotPasswordSchema>;
