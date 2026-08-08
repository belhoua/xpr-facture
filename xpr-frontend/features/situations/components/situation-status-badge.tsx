import { useTranslations } from "next-intl";

import { StatusBadge, type DocumentStatus } from "@/components/patterns/status-badge";

/**
 * Badge d'état de règlement d'une situation.
 *
 * Il RÉINTERPRÈTE la couleur de `sent`. Sur une facture, « envoyée » est un
 * état neutre — le document est parti, l'échéance court, rien n'est anormal :
 * bleu. Sur une situation, le même code signifie « aucune avance encaissée »,
 * qui est la préoccupation même de l'écran : il prend donc le rouge de
 * `overdue`.
 *
 * C'est une décision d'AFFICHAGE, pas de données : le serveur reste maître du
 * statut, et `DocumentStatus` garde ses neuf valeurs. Rien n'est stocké
 * différemment — seul le libellé et la teinte changent, dans ce module.
 */
const SITUATION_TONE: Partial<Record<DocumentStatus, DocumentStatus>> = {
  sent: "overdue",
};

/** États dont le libellé est propre à la situation. */
const OWN_LABEL: readonly DocumentStatus[] = [
  "sent",
  "in_progress",
  "partial",
  "paid",
];

export function SituationStatusBadge({ status }: { status: DocumentStatus }) {
  const t = useTranslations("situations.status");
  const tStatus = useTranslations("status");

  // Les quatre états du cycle de règlement ont leur libellé (« Non payé »
  // plutôt que « Envoyée »). Les autres — brouillon, annulée — retombent sur le
  // vocabulaire commun, qu'il n'y a aucune raison de dédoubler.
  const label = OWN_LABEL.includes(status) ? t(status) : tStatus(status);

  return <StatusBadge status={SITUATION_TONE[status] ?? status} label={label} />;
}
