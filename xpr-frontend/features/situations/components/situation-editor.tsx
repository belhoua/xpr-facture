"use client";

import { useQuery } from "@tanstack/react-query";
import { useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Skeleton } from "@/components/ui/skeleton";
import { fetchSituation, situationKeys } from "@/features/situations/api/situations";
import { SituationForm } from "@/features/situations/components/situation-form";
import { toApiProblem } from "@/lib/api/client";

/**
 * Charge une situation existante, puis la confie au formulaire.
 *
 * Le chargement est CLIENT et non serveur : l'authentification repose sur les
 * cookies de session Sanctum, qu'un Server Component ne porte pas — il ne
 * pourrait pas appeler l'API en tant qu'utilisateur connecté.
 *
 * Les trois états sont traités ici pour que le formulaire n'ait jamais à
 * composer avec une situation absente : il reçoit un document, ou n'est pas
 * monté (§6, gestion des quatre états).
 */
export function SituationEditor({ id }: { id: string }) {
  const t = useTranslations("situations");
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: situationKeys.detail(id),
    queryFn: () => fetchSituation(id),
  });

  if (isPending) {
    return (
      <div className="max-w-2xl space-y-4">
        {Array.from({ length: 5 }, (_, index) => (
          <Skeleton key={index} className="h-10 w-full" />
        ))}
      </div>
    );
  }

  if (isError) {
    return (
      <ErrorState detail={toApiProblem(error).detail} onRetry={() => void refetch()} />
    );
  }

  // Un document d'un autre type atteint par une URL forgée : le formulaire de
  // situation ne sait pas éditer une facture, et le serveur refuserait de
  // toute façon d'en écraser le total. On le dit plutôt que d'afficher un
  // formulaire qui échouera à l'envoi.
  if (data.type !== "situation") {
    return <ErrorState detail={t("client.wrongType")} />;
  }

  return <SituationForm situation={data} />;
}
