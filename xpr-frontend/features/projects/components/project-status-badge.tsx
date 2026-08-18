import { useTranslations } from "next-intl";

import {
  StatusBadge,
  type DocumentStatus,
} from "@/components/patterns/status-badge";
import type { ProjectStatus } from "@/features/projects/schemas/project";

/**
 * Badge d'avancement d'un projet.
 *
 * Il réutilise la sémantique de couleur de `StatusBadge` plutôt que d'en
 * introduire une : §11 impose une seule couleur d'accent et une correspondance
 * statut → teinte stable dans toute l'application. C'est donc une TRADUCTION de
 * vocabulaire, pas un nouveau code visuel.
 */
const PROJECT_TONE: Record<ProjectStatus, DocumentStatus> = {
  // Le travail suit son cours : le bleu que portent déjà « envoyé » et
  // « en cours ».
  in_progress: "in_progress",
  // Achevé : c'est abouti, il n'y a plus rien à faire — le vert d'une facture
  // payée.
  completed: "paid",
  // En suivi : rendu, mais le dossier reste ouvert. L'orange du partiel, qui
  // dit exactement cela — une étape franchie, pas la dernière.
  monitoring: "partial",
  canceled: "cancelled",
};

export function ProjectStatusBadge({ status }: { status: ProjectStatus }) {
  const t = useTranslations("projects.status");

  return <StatusBadge status={PROJECT_TONE[status]} label={t(status)} />;
}
