"use client";

import { useQuery } from "@tanstack/react-query";
import { Eye, Search, UsersRound } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import type { Partner } from "@/features/partners/schemas/partner";
import { toApiProblem } from "@/lib/api/client";
import { Link } from "@/lib/i18n/navigation";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/**
 * Point d'entrée « situations par client » : on choisit d'abord le client,
 * puis on consulte ses situations et ses totaux.
 *
 * L'écran liste les CLIENTS et non les situations regroupées. Une liste
 * groupée aurait obligé à charger toutes les situations de toutes les sociétés
 * clientes pour n'en afficher que les en-têtes ; ici chaque ligne ne coûte que
 * la fiche du tiers, et le détail se charge à la demande.
 *
 * Le filtre de recherche est appliqué CÔTÉ SERVEUR (`/partners?search=`),
 * comme sur l'écran Tiers : filtrer localement une liste paginée masquerait
 * les clients absents de la page courante.
 */
export function SituationsByClientView() {
  const t = useTranslations("situations");
  const tCommon = useTranslations("common");

  const [search, setSearch] = useState("");

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);

  const filters = { type: "client" as const, search: debouncedSearch };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: partnerKeys.list(filters),
    queryFn: () => fetchPartners(filters),
  });

  const columns: readonly Column<Partner>[] = [
    {
      id: "legalName",
      header: t("byClient.columns.legalName"),
      cell: (row) => <span className="font-medium">{row.legalName}</span>,
    },
    {
      id: "email",
      header: t("byClient.columns.email"),
      cell: (row) => (
        <span className="text-muted-foreground">{row.email ?? "—"}</span>
      ),
      hideBelow: "md",
    },
    {
      id: "phone",
      header: t("byClient.columns.phone"),
      cell: (row) => (
        <span className="text-muted-foreground">{row.phone ?? "—"}</span>
      ),
      hideBelow: "md",
    },
    {
      id: "ice",
      header: t("byClient.columns.ice"),
      cell: (row) => <span className="amount">{row.ice ?? "—"}</span>,
      hideBelow: "lg",
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => (
        <Button
          asChild
          size="sm"
          // Le vert est ici SÉMANTIQUE et non décoratif : il reprend la teinte
          // « réglé » du jeu de statuts, cohérente avec un écran qui parle
          // d'encaissements. Pas de nouvelle couleur introduite (§11).
          className="bg-status-paid hover:bg-status-paid/90 text-white"
        >
          <Link href={`/situations/by-client/${row.id}`}>
            <Eye className="size-4" aria-hidden />
            {t("actions.viewSituations")}
          </Link>
        </Button>
      ),
    },
  ];

  return (
    <>
      <PageHeader
        title={t("byClient.title")}
        description={t("byClient.description")}
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1">
          <Search
            className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
            aria-hidden
          />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("byClient.searchPlaceholder")}
            aria-label={tCommon("search")}
            className="ps-8"
          />
        </div>
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        empty={{
          icon: UsersRound,
          title: t("byClient.empty.title"),
          description: t("byClient.empty.description"),
        }}
      />
    </>
  );
}
