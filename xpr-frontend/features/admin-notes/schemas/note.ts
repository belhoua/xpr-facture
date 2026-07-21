import { z } from "zod";

/**
 * Contrat des notes/tickets adressés aux administrateurs de la plateforme.
 *
 * Les bornes de longueur sont DUPLIQUÉES côté Laravel : la validation Zod est
 * un confort d'ergonomie, elle ne protège rien (CLAUDE.md §10 — « le frontend
 * ne protège rien »).
 */
export const NOTE_PRIORITIES = ["low", "normal", "high"] as const;

export const adminNoteSchema = z.object({
  id: z.uuid(),
  subject: z.string(),
  body: z.string(),
  priority: z.enum(NOTE_PRIORITIES),
  status: z.enum(["open", "answered", "closed"]),
  createdAt: z.iso.datetime(),
});

export const adminNoteListSchema = z.object({
  data: z.array(adminNoteSchema),
});

export const createNoteSchema = z.object({
  subject: z.string().min(4, "validation.minLength").max(150, "validation.tooLong"),
  body: z.string().min(10, "validation.minLength").max(5000, "validation.tooLong"),
  priority: z.enum(NOTE_PRIORITIES),
});

export type NotePriority = (typeof NOTE_PRIORITIES)[number];
export type AdminNote = z.infer<typeof adminNoteSchema>;
export type CreateNoteValues = z.infer<typeof createNoteSchema>;
