"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  ArrowDownRight,
  ArrowUpRight,
  FileText,
  Lock,
  MoreHorizontal,
  Pencil,
  Plus,
  Scale,
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
  DropdownMenuLabel,
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
import { dashboardKeys } from "@/features/dashboard/api/dashboard";
import type { CashMovement } from "@/features/cash/schemas/cash";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";
import { cn } from "@/lib/utils";
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
 * Mouvements de caisse : trois cumuls en tête, le journal complet dessous.
 *
 * ── Ce que l'écran montrait jusqu'au 2026-08-25 ───────────────────────────
 *
 * Les seuls ENCAISSEMENTS, et un seul total. C'était un état des recettes, pas
 * une balance de trésorerie ; l'exploitant demande désormais les deux sens et
 * leur solde, ce qui referme un défaut au passage — le formulaire acceptait
 * déjà la saisie d'un décaissement, que le journal filtré ne réaffichait
 * jamais. On pouvait donc enregistrer une sortie et ne plus jamais la revoir.
 *
 * Le filtre `direction: "inflow"` est retiré pour cette raison, et pour une
 * seconde : les trois cartes doivent décrire les lignes en dessous. Un
 * « décaissement total » posé au-dessus d'un journal qui n'en contient aucun
 * annoncerait un chiffre invérifiable sur l'écran qui le porte.
 *
 * Les cumuls viennent du SERVEUR — `inflowCents`, `outflowCents` (en valeur
 * absolue) et `balanceCents` — jamais d'une somme des lignes reçues : le solde
 * net recalculé ici ferait cohabiter deux arrondis, et c'est l'écran qui
 * afficherait l'écart.
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

  // Aucun filtre de sens : le journal porte les entrées ET les sorties, faute
  // de quoi les trois cartes du haut décriraient autre chose que ses lignes.
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: cashKeys.summary(period),
    queryFn: () => fetchCashSummary(period),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteCashMovement(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: cashKeys.all });
      // Le solde de caisse figure aussi sur le tableau de bord.
      await queryClient.invalidateQueries({ queryKey: dashboardKeys.all });
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
      id: "charge",
      header: t("columns.charge"),
      hideBelow: "lg",
      // Le tiret dit « non classée » — le cas d'un encaissement, où la nature
      // n'a pas de sens, comme d'une sortie saisie sans classement.
      cell: (row) =>
        row.charge ?? <span className="text-muted-foreground">—</span>,
    },
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
      // Le SENS se lit sur la couleur, comme dans les trois cartes du haut :
      // vert ce qui entre, rouge ce qui sort. La branche avait été retirée
      // quand le journal ne portait que des encaissements ; elle revient avec
      // eux (2026-08-25). Le montant garde son signe — la couleur seule ne
      // suffit pas sur une photocopie en noir et blanc.
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
      cell: (row) =>
        // Un RÈGLEMENT ne se corrige que depuis sa facture, dont il dérive le
        // cumul encaissé et le statut. Le serveur refuse d'ailleurs les deux
        // gestes en 409 (`CashMovementWriteService::assertEditable`) — mais
        // c'est bien la règle métier, pas le refus, qui les retire ici.
        //
        // Un MENU aux entrées désactivées, et non le tiret muet d'avant, qui
        // portait l'explication dans un `title` qu'il fallait survoler au pixel
        // près, sur un caractère ne ressemblant à aucun bouton. Même
        // raisonnement que le menu des devis — une action absente sans un mot se
        // lit comme un dysfonctionnement. L'entrée vers la facture, elle, est
        // bien active : c'est le seul endroit où ce mouvement se corrige, et il
        // vaut mieux y conduire que de dire d'y aller.
        row.source === "payment" ? (
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
            <DropdownMenuContent align="end" className="w-72">
              <DropdownMenuLabel className="text-muted-foreground text-xs font-normal whitespace-normal">
                {t("readOnly")}
              </DropdownMenuLabel>

              {/* `invoiceId` est nul si la facture a été supprimée depuis :
                  l'entrée disparaît alors plutôt que d'ouvrir une page vide. */}
              {row.invoiceId !== null && (
                <DropdownMenuItem asChild>
                  <Link href={`/invoices?document=${row.invoiceId}`}>
                    <FileText aria-hidden />
                    {t("actions.openInvoice", {
                      number: row.invoiceNumber ?? "",
                    })}
                  </Link>
                </DropdownMenuItem>
              )}

              <DropdownMenuItem disabled>
                <Lock aria-hidden />
                {t("actions.mirrored")}
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
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

      {/* Trois cumuls de la période, dans l'ordre où on les lit : ce qui est
          entré, ce qui est sorti, ce qui reste. La grille s'étire ici sur toute
          la largeur — contrairement à la tuile unique d'avant, que sa taille
          réduite mettait en valeur — parce que trois grandeurs qui se comparent
          doivent partager la même ligne de base et la même largeur. */}
      <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
        <StatCard
          label={t("inflowTotal")}
          icon={ArrowUpRight}
          tone="positive"
          loading={isPending}
          value={
            data ? formatMoney(data.inflowCents, locale, data.currency) : "—"
          }
        />
        <StatCard
          label={t("outflowTotal")}
          icon={ArrowDownRight}
          tone="negative"
          loading={isPending}
          // `outflowCents` arrive en VALEUR ABSOLUE du serveur : la carte
          // annonce « ce qui est sorti », une grandeur positive. Le signe
          // appartient aux lignes du journal, pas à un cumul intitulé
          // « décaissement total ».
          value={
            data ? formatMoney(data.outflowCents, locale, data.currency) : "—"
          }
        />
        <StatCard
          label={t("netBalance")}
          icon={Scale}
          // NEUTRE, délibérément : le solde n'est ni une entrée ni une sortie,
          // et lui donner une troisième couleur ferait trois signaux là où deux
          // suffisent. Son SIGNE, lui, se lit sur le montant — un solde négatif
          // s'affiche avec son moins.
          loading={isPending}
          // Valeur du SERVEUR, pas `inflow - outflow` recalculé ici : les
          // règlements de factures entrent dans le solde par un autre chemin
          // que les mouvements saisis (cf. `CashSummaryService`), et refaire la
          // soustraction à l'écran finirait par diverger de la base.
          value={
            data ? formatMoney(data.balanceCents, locale, data.currency) : "—"
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
        // La ligne visée est NOMMÉE : le journal aligne des montants proches et
        // des libellés qui se ressemblent, et une confirmation anonyme fait
        // valider la suppression de la ligne d'à côté. Même règle que la
        // suppression d'un document, qui nomme son numéro.
        description={
          deleteTarget
            ? t("delete.described", {
                label: deleteTarget.label,
                amount: formatMoney(
                  Math.abs(deleteTarget.amountCents),
                  locale,
                  deleteTarget.currency,
                ),
              })
            : t("delete.description")
        }
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />
    </>
  );
}
