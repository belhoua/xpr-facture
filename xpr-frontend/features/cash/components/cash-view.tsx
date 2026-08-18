"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrowUpRight,
  MoreHorizontal,
  Pencil,
  Plus,
  Trash2,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { StatCard } from "@/components/patterns/stat-card";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  cashKeys,
  deleteCashMovement,
  fetchCashSummary,
} from "@/features/cash/api/cash";
import type { CashMovement } from "@/features/cash/schemas/cash";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { useDeferredMount } from "@/lib/use-deferred-mount";

/**
 * Panneau chargé à la demande : son code — formulaire complet, validation
 * Zod, sélecteurs — n'a aucune raison de partir avec la liste, qui s'ouvre
 * sur un tableau. Le téléchargement a lieu à la première ouverture
 * (cf. `useDeferredMount`).
 */
const CashMovementFormDialog = dynamic(
  () => import("@/features/cash/components/cash-movement-form-dialog").then((m) => m.CashMovementFormDialog),
  { ssr: false },
);

const PERIODS = ["last7", "last30", "last90", "year"] as const;

/**
 * Suivi des ENCAISSEMENTS : un total en tête, le journal des entrées dessous.
 *
 * L'écran ne montre volontairement ni solde ni décaissements. C'est un état des
 * recettes, pas une balance de trésorerie — deux lectures distinctes qui, mises
 * côte à côte, se faisaient concurrence sans que rien ne dise laquelle
 * répondait à la question posée.
 *
 * Le filtrage est demandé au SERVEUR (`direction: "inflow"`) et non appliqué
 * ici : trier ou filtrer une liste déjà reçue ne porterait que sur ce qui a été
 * transmis. Les trois cumuls, eux, restent calculés sur la période entière —
 * `inflowCents` est donc le total encaissé, que le filtre soit posé ou non.
 *
 * La liste a DEUX sources, fusionnées par le serveur : les écritures saisies
 * ici, et les règlements reçus sur les factures. Le champ `source` les
 * distingue, et c'est la seule différence que l'écran en tire — un règlement ne
 * se corrige que depuis sa facture, qui en dérive le cumul encaissé et le
 * statut. Une écriture saisie, elle, se corrige et se supprime librement :
 * aucune immuabilité de ce côté.
 */
export function CashView() {
  const t = useTranslations("cash");
  const tMethods = useTranslations("cash.methods");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();
  const [period, setPeriod] = useState<string>("last30");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<CashMovement | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<CashMovement | null>(null);

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: cashKeys.summary(period, "inflow"),
    queryFn: () => fetchCashSummary(period, "inflow"),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteCashMovement(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: cashKeys.all });
      setDeleteTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (movement: CashMovement) => {
    setEditing(movement);
    setFormOpen(true);
  };

  const columns: readonly Column<CashMovement>[] = [
    {
      id: "date",
      header: t("columns.date"),
      cell: (row) => (
        <span className="text-muted-foreground tabular">
          {formatDate(row.occurredAt, locale)}
        </span>
      ),
    },
    {
      id: "client",
      header: t("columns.client"),
      cell: (row) =>
        row.clientName ?? (
          // `null` recouvre deux cas — mouvement sans tiers, ou tiers archivé.
          // Un tiret les dit tous les deux sans prétendre les distinguer, ce
          // que la donnée reçue ne permet de toute façon pas.
          <span className="text-muted-foreground">—</span>
        ),
    },
    { id: "label", header: t("columns.label"), cell: (row) => row.label },
    {
      id: "method",
      header: t("columns.method"),
      hideBelow: "md",
      cell: (row) => (
        <span className="text-muted-foreground">{tMethods(row.method)}</span>
      ),
    },
    {
      id: "register",
      header: t("columns.register"),
      hideBelow: "lg",
      cell: (row) => (
        // `null` sur un règlement de facture : il n'entre dans aucune caisse
        // physique — un virement arrive en banque. Le tiret le dit sans
        // inventer un tiroir-caisse qui n'existe pas.
        <span className="text-muted-foreground">{row.registerName ?? "—"}</span>
      ),
    },
    {
      id: "amount",
      header: t("columns.amount"),
      align: "end",
      // Plus de branche sur le signe : la liste ne porte que des encaissements,
      // et conserver le rouge du décaissement laisserait croire qu'il pourrait
      // encore s'en afficher un.
      cell: (row) => (
        <span className="amount text-status-paid font-medium">
          {formatMoney(row.amountCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "actions",
      header: t("columns.actions"),
      align: "end",
      cell: (row) =>
        // Un RÈGLEMENT ne se corrige que depuis sa facture, dont il dérive le
        // cumul encaissé et le statut. Les deux actions retomberaient d'ailleurs
        // sur un 404 — l'identifiant n'est pas celui d'un mouvement — mais
        // c'est bien la règle métier, pas la route absente, qui les retire ici.
        row.source === "payment" ? (
          <span className="text-muted-foreground" title={t("readOnly")}>
            —
          </span>
        ) : (
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
              onSelect={() => setDeleteTarget(row)}
            >
              <Trash2 aria-hidden />
              {t("actions.delete")}
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
          <>
            <Select value={period} onValueChange={setPeriod}>
              <SelectTrigger size="sm" className="w-44">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {PERIODS.map((value) => (
                  <SelectItem key={value} value={value}>
                    {tCommon(`period.${value}`)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t("newEntry")}
            </Button>
          </>
        }
      />

      {/* Seule carte de l'écran, et volontairement PAS étirée sur toute la
          largeur : un chiffre unique perdu au milieu d'un bandeau de 1200 px se
          lit moins bien qu'une tuile à sa mesure. C'est le fait d'être seule,
          non sa taille, qui la met en valeur. */}
      <div className="mb-3 sm:max-w-sm">
        <StatCard
          label={t("inflowTotal")}
          icon={ArrowUpRight}
          loading={isPending}
          value={
            data ? formatMoney(data.inflowCents, locale, data.currency) : "—"
          }
        />
      </div>

      <DataTable
        rows={data?.movements ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        empty={{
          icon: Wallet,
          title: t("empty.title"),
          description: t("empty.description"),
          action: (
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t("newEntry")}
            </Button>
          ),
        }}
      />

      {formOpenMounted && (
        <CashMovementFormDialog
          open={formOpen}
          onOpenChange={setFormOpen}
          movement={editing}
        />
      )}

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description")}
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />
    </>
  );
}
