"use client";

import { useQuery } from "@tanstack/react-query";
import { FileText, Printer } from "lucide-react";
import { useTranslations } from "next-intl";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { conventionKeys, fetchConvention } from "@/features/conventions/api/conventions";
import { ConventionDeposits } from "@/features/conventions/components/convention-deposits";
import { ConventionForm } from "@/features/conventions/components/convention-form";
import { isConventionEditable } from "@/features/conventions/schemas/convention";
import { toApiProblem } from "@/lib/api/client";
import { Link } from "@/lib/i18n/navigation";

/**
 * Charge une convention existante, puis la confie au formulaire et au suivi de
 * dossier.
 *
 * Le chargement est CLIENT et non serveur : l'authentification repose sur les
 * cookies de session Sanctum, qu'un Server Component ne porte pas — il ne
 * pourrait pas appeler l'API en tant qu'utilisateur connecté.
 */
export function ConventionEditor({ id }: { id: string }) {
  const t = useTranslations("conventions");
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: conventionKeys.detail(id),
    queryFn: () => fetchConvention(id),
  });

  if (isPending) {
    return (
      <div className="max-w-3xl space-y-4">
        {Array.from({ length: 6 }, (_, index) => (
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

  return (
    <>
      <div className="mb-6 flex flex-wrap items-center gap-2">
        <Button variant="outline" size="sm" asChild>
          <Link href={`/conventions/${data.id}/print`}>
            <Printer className="size-4" aria-hidden />
            {t("actions.print")}
          </Link>
        </Button>

        {/* Le devis ou la facture d'origine reste à un clic : c'est la première
            chose qu'on rouvre pour vérifier un montant contesté. */}
        {data.sourceDocumentId !== null ? (
          <Button variant="ghost" size="sm" asChild>
            <Link href={`/quotes?document=${data.sourceDocumentId}`}>
              <FileText className="size-4" aria-hidden />
              {t("actions.openSource", {
                number: data.sourceDocumentNumber ?? "—",
              })}
            </Link>
          </Button>
        ) : null}
      </div>

      {/* Une convention ANNULÉE ne se modifie plus (le serveur répond 409) : on
          le dit au lieu d'afficher un formulaire voué à l'échec. */}
      {isConventionEditable(data) ? (
        <ConventionForm convention={data} />
      ) : (
        <p className="text-muted-foreground max-w-3xl rounded-md border border-dashed p-6 text-sm">
          {t("cancelledNotice")}
        </p>
      )}

      <ConventionDeposits convention={data} />
    </>
  );
}
