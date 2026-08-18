"use client";

import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  Archive,
  Contact,
  MoreHorizontal,
  Pencil,
  Plus,
  Search,
} from "lucide-react";
import { useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  archivePartner,
  fetchPartners,
  partnerKeys,
} from "@/features/partners/api/partners";
import {
  PARTNER_TYPES,
  type Partner,
  type PartnerType,
} from "@/features/partners/schemas/partner";
import { toApiProblem } from "@/lib/api/client";
import { useDeferredMount } from "@/lib/use-deferred-mount";
import { useDebouncedValue } from "@/lib/use-debounced-value";

/**
 * Panneau chargé à la demande : son code — formulaire complet, validation
 * Zod, sélecteurs — n'a aucune raison de partir avec la liste, qui s'ouvre
 * sur un tableau. Le téléchargement a lieu à la première ouverture
 * (cf. `useDeferredMount`).
 */
const PartnerFormDialog = dynamic(
  () => import("@/features/partners/components/partner-form-dialog").then((m) => m.PartnerFormDialog),
  { ssr: false },
);

/**
 * Options du filtre. Dérivées de l'énumération, non recopiées : un type ajouté
 * au contrat apparaît ici sans qu'on y pense, et c'est précisément ce qu'on
 * oublie de faire à la main.
 */
const TYPE_FILTERS = ["all", ...PARTNER_TYPES] as const;

/**
 * Traitement visuel du badge par type. Table exhaustive plutôt qu'un ternaire :
 * ajouter un type au contrat sans lui donner d'apparence casse la compilation,
 * là où un `?:` l'aurait rangé dans la branche « sinon » sans le dire.
 */
const BADGE_VARIANTS: Record<
  PartnerType,
  "default" | "secondary" | "outline"
> = {
  client: "secondary",
  supplier: "secondary",
  both: "default",
  intermediary: "outline",
};

/**
 * Répertoire des tiers : clients, fournisseurs et intermédiaires dans une seule
 * liste, filtrée par rôle. Un tiers `both` remonte dans les deux filtres
 * commerciaux, un `intermediary` seulement sous le sien — c'est le serveur qui
 * applique cette règle, l'interface ne la rejoue pas.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `partnerKeys.all` plutôt que de patcher chaque cache filtré.
 */
export function PartnersView() {
  const t = useTranslations("partners");
  const tCommon = useTranslations("common");
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);
  const [type, setType] = useState<PartnerType | "all">("all");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Partner | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<Partner | null>(null);

  const filters = { search: debouncedSearch, type };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: partnerKeys.list(filters),
    queryFn: () => fetchPartners(filters),
    // La liste PRÉCÉDENTE reste affichée pendant que la nouvelle arrive :
    // sans cela, chaque recherche renvoyait le tableau à ses squelettes,
    // et l'écran clignotait à chaque pause de frappe.
    placeholderData: keepPreviousData,
  });

  const archiveMutation = useMutation({
    mutationFn: (id: string) => archivePartner(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: partnerKeys.all });
      setArchiveTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (partner: Partner) => {
    setEditing(partner);
    setFormOpen(true);
  };

  const columns: readonly Column<Partner>[] = [
    {
      id: "name",
      header: t("columns.name"),
      cell: (row) => (
        <div className="flex flex-col">
          <span className="font-medium">{row.displayName}</span>
          {/* L'enseigne prime à l'affichage : on rappelle la raison sociale
              en dessous, elle seule a valeur légale sur un document. */}
          {row.tradeName && (
            <span className="text-muted-foreground text-xs">
              {row.legalName}
            </span>
          )}
        </div>
      ),
    },
    {
      id: "type",
      header: t("columns.type"),
      // Trois traitements pour quatre valeurs, et c'est voulu : `client` et
      // `supplier` sont le régime ordinaire (secondaire, discret), `both` se
      // signale parce qu'il vaut pour deux, et `intermediary` se distingue des
      // trois parce qu'il n'est PAS un sens de facturation — le contour dit
      // « à part » sans introduire une couleur de plus (§11).
      cell: (row) => (
        <Badge variant={BADGE_VARIANTS[row.type]}>{t(`types.${row.type}`)}</Badge>
      ),
    },
    {
      id: "ice",
      header: t("columns.ice"),
      hideBelow: "lg",
      cell: (row) => (
        <span className="tabular text-muted-foreground">{row.ice ?? "—"}</span>
      ),
    },
    {
      id: "city",
      header: t("columns.city"),
      hideBelow: "md",
      cell: (row) => row.city ?? "—",
    },
    {
      id: "contact",
      header: t("columns.contact"),
      hideBelow: "lg",
      cell: (row) => (
        <span className="text-muted-foreground">{row.email ?? row.phone ?? "—"}</span>
      ),
    },
    {
      id: "terms",
      header: t("columns.paymentTerms"),
      align: "end",
      hideBelow: "md",
      cell: (row) => (
        <span className="tabular">
          {row.paymentTermsDays === 0
            ? t("immediate")
            : t("days", { count: row.paymentTermsDays })}
        </span>
      ),
    },
    {
      id: "actions",
      header: t("columns.actions"),
      align: "end",
      cell: (row) => (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={t("actions.open")}
              className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
            >
              <MoreHorizontal aria-hidden />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-44">
            <DropdownMenuItem onSelect={() => openEdit(row)}>
              <Pencil aria-hidden />
              {t("actions.edit")}
            </DropdownMenuItem>
            <DropdownMenuItem
              variant="destructive"
              onSelect={() => setArchiveTarget(row)}
            >
              <Archive aria-hidden />
              {t("actions.archive")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      ),
    },
  ];

  const formOpenMounted = useDeferredMount(formOpen);

  return (
    <>
      <PageHeader
        title={t("title")}
        description={t("description")}
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus aria-hidden />
            {t("create")}
          </Button>
        }
      />

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:max-w-80">
          <Search
            className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
            aria-hidden
          />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("searchPlaceholder")}
            aria-label={tCommon("search")}
            className="ps-8"
          />
        </div>

        <Select
          value={type}
          onValueChange={(value) => setType(value as PartnerType | "all")}
        >
          <SelectTrigger className="w-40">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {TYPE_FILTERS.map((value) => (
              <SelectItem key={value} value={value}>
                {value === "all" ? tCommon("all") : t(`types.${value}`)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        empty={{
          icon: Contact,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t("create")}
            </Button>
          ),
        }}
      />

      {formOpenMounted && (
        <PartnerFormDialog
          open={formOpen}
          onOpenChange={setFormOpen}
          partner={editing}
        />
      )}

      <ConfirmDialog
        open={archiveTarget !== null}
        onOpenChange={(open) => !open && setArchiveTarget(null)}
        title={t("archive.title")}
        description={t("archive.description")}
        confirmLabel={t("archive.confirm")}
        pending={archiveMutation.isPending}
        onConfirm={() =>
          archiveTarget && archiveMutation.mutate(archiveTarget.id)
        }
      />
    </>
  );
}
