import { useTranslations } from "next-intl";

import { StatusBadge, type DocumentStatus } from "@/components/patterns/status-badge";
import type {
  ConventionStatus,
  DepositStatus,
} from "@/features/conventions/schemas/convention";

/**
 * Badges des conventions et des dépôts de dossier.
 *
 * Ils réutilisent la sémantique de couleur de `StatusBadge` plutôt que d'en
 * introduire une : §11 impose une seule couleur d'accent et une correspondance
 * statut → teinte stable dans toute l'application. Ce sont donc des TRADUCTIONS
 * de vocabulaire, pas de nouveaux codes visuels — un dossier « validé » emprunte
 * le vert d'une facture payée parce que les deux disent la même chose : c'est
 * abouti, il n'y a plus rien à faire.
 */

const CONVENTION_TONE: Record<ConventionStatus, DocumentStatus> = {
  draft: "draft",
  sent: "sent",
  // Le contrat est signé : engagement pris, comme une affaire acceptée.
  signed: "accepted",
  cancelled: "cancelled",
};

const DEPOSIT_TONE: Record<DepositStatus, DocumentStatus> = {
  // Déposé au guichet : parti, en attente — le neutre de « envoyé ».
  deposited: "sent",
  in_progress: "in_progress",
  validated: "paid",
  // Rejeté : c'est l'état qui APPELLE une action, il prend le rouge du refus.
  rejected: "refused",
};

export function ConventionStatusBadge({ status }: { status: ConventionStatus }) {
  const t = useTranslations("conventions.status");

  return <StatusBadge status={CONVENTION_TONE[status]} label={t(status)} />;
}

export function DepositStatusBadge({ status }: { status: DepositStatus }) {
  const t = useTranslations("deposits.status");

  return <StatusBadge status={DEPOSIT_TONE[status]} label={t(status)} />;
}
