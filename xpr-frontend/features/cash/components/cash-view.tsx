"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrowDownLeft,
  ArrowUpRight,
  MoreHorizontal,
  Pencil,
  Plus,
  Trash2,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
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
import { CashMovementFormDialog } from "@/features/cash/components/cash-movement-form-dialog";
import type { CashMovement } from "@/features/cash/schemas/cash";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { cn } from "@/lib/utils";

const PERIODS = ["last7", "last30", "last90", "year"] as const;

/**
 * Suivi des flux de caisse : trois soldes en tête, journal des mouvements
 * dessous, CRUD complet. Les décaissements sont affichés en négatif ET en
 * rouge — redonder le signe par la couleur évite de lire un « -1 250 » comme
 * un encaissement en diagonale. Aucune immuabilité ici : un mouvement se
 * corrige et se supprime librement.
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
    queryKey: cashKeys.summary(period),
    queryFn: () => fetchCashSummary(period),
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
        <span className="text-muted-foreground">{row.registerName}</span>
      ),
    },
    {
      id: "amount",
      header: t("columns.amount"),
      align: "end",
      cell: (row) => (
        <span
          className={cn(
            "amount font-medium",
            row.amountCents < 0 ? "text-status-overdue" : "text-status-paid",
          )}
        >
          {formatMoney(row.amountCents, locale, row.currency)}
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

      <div className="mb-3 grid gap-3 sm:grid-cols-3">
        <StatCard
          label={t("balance")}
          icon={Wallet}
          loading={isPending}
          value={
            data ? formatMoney(data.balanceCents, locale, data.currency) : "—"
          }
        />
        <StatCard
          label={t("inflow")}
          icon={ArrowUpRight}
          loading={isPending}
          value={
            data ? formatMoney(data.inflowCents, locale, data.currency) : "—"
          }
        />
        <StatCard
          label={t("outflow")}
          icon={ArrowDownLeft}
          loading={isPending}
          value={
            data ? formatMoney(data.outflowCents, locale, data.currency) : "—"
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

      <CashMovementFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        movement={editing}
      />

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
