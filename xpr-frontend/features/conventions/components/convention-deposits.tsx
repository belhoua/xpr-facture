"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Pencil, Plus, Printer, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { Button } from "@/components/ui/button";
import {
  conventionKeys,
  deleteDeposit,
  depositKeys,
} from "@/features/conventions/api/conventions";
import { DepositStatusBadge } from "@/features/conventions/components/convention-status-badge";
import { DepositFormDialog } from "@/features/conventions/components/deposit-form-dialog";
import type {
  Convention,
  FileDeposit,
} from "@/features/conventions/schemas/convention";
import { formatDate } from "@/lib/format";
import { Link } from "@/lib/i18n/navigation";

/**
 * Suivi du dossier D'UNE convention, affiché sous son formulaire.
 *
 * Pas un `DataTable` : on montre ici deux ou trois dépôts, pas une liste
 * filtrable — l'écran transverse `/deposits` s'en charge. Une liste de cartes se
 * lit mieux à ce volume, et évite d'entraîner l'appareil de filtres, de tri et
 * d'états vides d'un tableau pour trois lignes.
 *
 * Les dépôts viennent de la convention DÉJÀ CHARGÉE (`deposits`) : une requête
 * de plus sur un objet qu'on tient en main n'apporterait rien.
 */
export function ConventionDeposits({ convention }: { convention: Convention }) {
  const t = useTranslations("deposits");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<FileDeposit | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<FileDeposit | null>(null);

  const deposits = convention.deposits ?? [];

  const removal = useMutation({
    mutationFn: (id: string) => deleteDeposit(id),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: depositKeys.all }),
        queryClient.invalidateQueries({ queryKey: conventionKeys.all }),
      ]);
      setDeleteTarget(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  return (
    <section className="mt-10 max-w-3xl">
      <div className="mb-3 flex items-center justify-between gap-2">
        <div>
          <h2 className="font-heading text-base font-semibold">{t("title")}</h2>
          <p className="text-muted-foreground text-sm">{t("panelDescription")}</p>
        </div>
        <Button size="sm" variant="outline" onClick={openCreate}>
          <Plus className="size-4" aria-hidden />
          {t("actions.create")}
        </Button>
      </div>

      {deposits.length === 0 ? (
        <p className="text-muted-foreground rounded-md border border-dashed p-6 text-center text-sm">
          {t("empty.panel")}
        </p>
      ) : (
        <ul className="space-y-2">
          {deposits.map((deposit) => (
            <li
              key={deposit.id}
              className="flex flex-wrap items-center gap-3 rounded-md border p-3"
            >
              <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2">
                  <span className="amount font-medium">{deposit.reference}</span>
                  <DepositStatusBadge status={deposit.status} />
                </p>
                <p className="text-muted-foreground truncate text-sm">
                  {deposit.organisation} ·{" "}
                  {t("panelDates", {
                    deposited: formatDate(deposit.depositedAt, locale),
                    decided: deposit.decidedAt
                      ? formatDate(deposit.decidedAt, locale)
                      : "—",
                  })}
                </p>
              </div>

              <div className="flex items-center gap-1">
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={tCommon("edit")}
                  onClick={() => {
                    setEditing(deposit);
                    setFormOpen(true);
                  }}
                >
                  <Pencil className="size-4" aria-hidden />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t("actions.print")}
                  asChild
                >
                  <Link href={`/deposits/${deposit.id}/print`}>
                    <Printer className="size-4" aria-hidden />
                  </Link>
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={tCommon("delete")}
                  className="text-destructive hover:text-destructive"
                  onClick={() => setDeleteTarget(deposit)}
                >
                  <Trash2 className="size-4" aria-hidden />
                </Button>
              </div>
            </li>
          ))}
        </ul>
      )}

      {/* La convention est connue : le champ de sélection reste masqué, et la
          référence du contrat est proposée par défaut — au premier dépôt, c'est
          elle qu'on redonne au guichet. */}
      <DepositFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        conventionId={convention.id}
        defaultReference={convention.dossierNumber ?? ""}
        deposit={editing}
      />

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description", {
          reference: deleteTarget?.reference ?? "",
        })}
        confirmLabel={tCommon("delete")}
        variant="destructive"
        pending={removal.isPending}
        onConfirm={() => deleteTarget && removal.mutate(deleteTarget.id)}
      />
    </section>
  );
}
