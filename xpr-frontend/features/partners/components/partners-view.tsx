"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  Archive,
  Contact,
  MoreHorizontal,
  Pencil,
  Plus,
  Search,
} from "lucide-react";
import { useTranslations } from "next-intl";
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
import { PartnerFormDialog } from "@/features/partners/components/partner-form-dialog";
import type {
  Partner,
  PartnerType,
} from "@/features/partners/schemas/partner";
import { toApiProblem } from "@/lib/api/client";

const TYPE_FILTERS = ["all", "client", "supplier", "both"] as const;

/**
 * Répertoire des tiers : clients et fournisseurs dans une seule liste, filtrée
 * par rôle commercial. Un tiers `both` remonte dans les deux filtres — c'est le
 * serveur qui applique cette règle, l'interface ne la rejoue pas.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `partnerKeys.all` plutôt que de patcher chaque cache filtré.
 */
export function PartnersView() {
  const t = useTranslations("partners");
  const tCommon = useTranslations("common");
  const queryClient = useQueryClient();

  const [search, setSearch] = useState("");
  const [type, setType] = useState<PartnerType | "all">("all");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Partner | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<Partner | null>(null);

  const filters = { search, type };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: partnerKeys.list(filters),
    queryFn: () => fetchPartners(filters),
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
      cell: (row) => (
        <Badge variant={row.type === "both" ? "default" : "secondary"}>
          {t(`types.${row.type}`)}
        </Badge>
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

      <PartnerFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        partner={editing}
      />

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
