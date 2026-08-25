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

/** Comptes de l'écran. Miroir de `ProjectSummaryController`. */
export const projectSummarySchema = z.object({
  count: z.int().nonnegative(),
  inProgress: z.int().nonnegative(),
  incomplete: z.int().nonnegative(),
  completed: z.int().nonnegative(),
});

export type ProjectSummary = z.infer<typeof projectSummarySchema>;

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
 * La fiche est-elle INCOMPLÈTE ?
 *
 * Deux manques, et deux seulement : pas de description, ou aucun livrable
 * annoncé. Ce sont les champs qu'un projet ouvert à la hâte laisse
 * systématiquement derrière lui — un titre, un client, et plus rien — et ceux
 * qu'il faut reprendre pour que la fiche serve à quelque chose.
 *
 * Le SERVICE n'entre pas dans le compte, bien qu'il soit lui aussi facultatif :
 * le référentiel des services naît vide, et l'y inclure marquerait « à
 * compléter » l'intégralité des projets d'une société qui ne s'en sert pas — un
 * signal qui ne redescend jamais à zéro n'en est plus un.
 *
 * Miroir exact de `ProjectService::INCOMPLETE_SQL`. Les deux DOIVENT dire la
 * même chose : le compte de la carte vient du serveur, le bandeau de la ligne se
 * décide ici, et ils se contrediraient sous les yeux de l'utilisateur.
 *
 * Le DÉTAIL de ce qui manque est rendu par `missingParts()`, dont cette
 * fonction n'est que le verdict.
 */
export function isIncomplete(project: Project): boolean {
  return missingParts(project).length > 0;
}

/** Ce qui manque à la fiche, dans l'ordre où on le renseigne. */
export type MissingPart = "description" | "deliverable";

/**
 * Le DÉTAIL de ce qui manque, et non le seul verdict.
 *
 * Ajouté le 2026-08-26 après un signalement précis : renseigner la description
 * d'un projet ne faisait pas disparaître le bandeau, et rien ne disait pourquoi.
 * Le message ne mentait pas — « pas encore de description OU d'étape livrée » —
 * mais il restait identique avant et après, ce qui se lit comme un écran qui
 * n'a pas pris en compte la saisie.
 *
 * Le bandeau nomme donc désormais ce qui reste à faire, et la liste raccourcit
 * à mesure qu'on la traite. C'est la même règle, énoncée autrement.
 *
 * Le SERVICE n'y entre toujours pas, bien qu'il soit lui aussi facultatif : le
 * référentiel se remplit à l'usage, et l'y inclure marquerait « à compléter »
 * tous les projets d'une société qui ne classe pas ses missions — un signal qui
 * ne redescend jamais à zéro n'en est plus un.
 */
export function missingParts(project: Project): readonly MissingPart[] {
  const parts: MissingPart[] = [];

  if ((project.description ?? "").trim() === "") {
    parts.push("description");
  }

  // `deliverableCount` est absent de certaines réponses (la relation n'est pas
  // toujours chargée) : `?? 0` le traite comme « aucun livrable connu », ce qui
  // penche du côté du signalement plutôt que du silence.
  if ((project.deliverableCount ?? project.deliverables?.length ?? 0) === 0) {
    parts.push("deliverable");
  }

  return parts;
}

/**
 * Un projet ANNULÉ ne progresse plus : lui pousser un pourcentage décrirait un
 * travail qui n'aura pas lieu. Miroir de `ProjectStatus::isTerminal()` — le
 * serveur répond 409, l'interface se contente de ne pas le proposer.
 */
export function isProgressEditable(project: Project): boolean {
  return project.status !== "canceled";
}
