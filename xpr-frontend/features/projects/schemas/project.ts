import { z } from "zod";

/**
 * Avancement de projet — miroir de `ProjectResource` et `DeliverableResource`.
 *
 * Le projet n'est PAS un document commercial : il a sa table, ses endpoints et
 * son schéma. Ni numéro de séquence, ni montant, ni exercice fiscal — rien de
 * ce que le §3 impose aux pièces ne s'y applique, puisqu'il n'est opposable à
 * personne.
 */

/** Miroir de `Projects\Enums\ProjectStatus`. */
export const PROJECT_STATUSES = [
  "in_progress",
  "completed",
  "monitoring",
  "canceled",
] as const;

export type ProjectStatus = (typeof PROJECT_STATUSES)[number];

/** Un livrable remis au client : ce qui est parti, et quand. */
export const deliverableSchema = z.object({
  id: z.uuid(),
  projectId: z.uuid(),
  title: z.string(),
  deliveryDate: z.iso.date(),
  notes: z.string().nullable(),
  createdAt: z.string().nullable(),
});

export type Deliverable = z.infer<typeof deliverableSchema>;

export const projectSchema = z.object({
  id: z.uuid(),
  title: z.string(),
  status: z.enum(PROJECT_STATUSES),
  progressPercentage: z.int().min(0).max(100),
  description: z.string().nullable(),

  partnerId: z.uuid(),
  /**
   * Absent quand la relation n'est pas chargée, `null` quand le client a été
   * archivé — le projet survit à son client, et l'écran doit pouvoir le dire
   * plutôt que planter.
   */
  clientName: z.string().nullable().optional(),

  /** Service dont relève le projet. `null` = non classé — le cas par défaut. */
  serviceId: z.uuid().nullable(),
  /**
   * Nom du service, rendu à plat pour la colonne SERVICE. Absent quand la
   * relation n'est pas chargée, `null` quand le projet n'est pas classé OU que
   * le service a été archivé depuis — l'écran affiche « — » dans les deux cas,
   * ce que la donnée reçue ne permet de toute façon pas de distinguer.
   */
  serviceName: z.string().nullable().optional(),

  /** Remis du plus récent au plus ancien : l'ordre vient du serveur. */
  deliverables: z.array(deliverableSchema).optional(),
  /** Compté par le serveur : la liste n'affiche que ce nombre. */
  deliverableCount: z.int().nonnegative().optional(),

  createdAt: z.string().nullable(),
  updatedAt: z.string().nullable(),
});

export type Project = z.infer<typeof projectSchema>;

export const projectListSchema = z.object({
  data: z.array(projectSchema),
  meta: z.object({
    total: z.int().nonnegative(),
    page: z.int().positive(),
    perPage: z.int().positive(),
  }),
});

export type ProjectList = z.infer<typeof projectListSchema>;

/* ------------------------------------------------------------- Formulaires */

export const projectFormSchema = z.object({
  /**
   * Client OBLIGATOIRE, comme sur une situation : l'écran filtre par client, et
   * un projet sans client y serait invisible — donc absent des listes de celui
   * pour qui on le mène.
   */
  partnerId: z.string().min(1, "validation.required"),
  /**
   * Service FACULTATIF : "" = aucun. Le référentiel naît vide, et l'exiger
   * interdirait de créer le premier projet. Un `<Select>` ne portant pas de
   * valeur nulle, la chaîne vide tient lieu de « Aucun » et redevient `null` à
   * la frontière API.
   */
  serviceId: z.string(),
  title: z
    .string()
    .trim()
    .min(2, "validation.required")
    .max(255, "validation.tooLong"),
  status: z.enum(PROJECT_STATUSES),
  /**
   * Pourcentage ENTIER : un avancement de chantier s'annonce au point près, et
   * une décimale n'ajouterait qu'une fausse précision sur une donnée
   * déclarative.
   */
  progressPercentage: z
    .number("validation.percent")
    .int("validation.percent")
    .min(0, "validation.percent")
    .max(100, "validation.percent"),
  description: z.string().trim().max(5000, "validation.tooLong"),
});

export type ProjectFormValues = z.infer<typeof projectFormSchema>;

export const deliverableFormSchema = z.object({
  title: z
    .string()
    .trim()
    .min(2, "validation.required")
    .max(255, "validation.tooLong"),
  deliveryDate: z.string().min(1, "validation.required"),
  notes: z.string().trim().max(5000, "validation.tooLong"),
});

export type DeliverableFormValues = z.infer<typeof deliverableFormSchema>;

/**
 * Un projet ANNULÉ ne progresse plus : lui pousser un pourcentage décrirait un
 * travail qui n'aura pas lieu. Miroir de `ProjectStatus::isTerminal()` — le
 * serveur répond 409, l'interface se contente de ne pas le proposer.
 */
export function isProgressEditable(project: Project): boolean {
  return project.status !== "canceled";
}
