"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Ban, FileOutput, FileSignature, Printer, Send } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { ErrorState } from "@/components/patterns/error-state";
import { StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Skeleton } from "@/components/ui/skeleton";
import {
  conventionKeys,
  createConventionFromDocument,
} from "@/features/conventions/api/conventions";
import {
  cancelDocument,
  convertDocument,
  documentKeys,
  fetchDocument,
  issueDocument,
} from "@/features/documents/api/documents";
import {
  isCancellable,
  isConvertible,
  isEditable,
  isIssuable,
  isTransferableToConvention,
  printRoute,
  type Document,
} from "@/features/documents/schemas/document";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link, useRouter } from "@/lib/i18n/navigation";

/**
 * Détail d'un document et actions de son cycle de vie.
 *
 * C'est ici que passent les actes fiscaux — émission, annulation, avoir — et
 * chacun est une requête dédiée côté serveur, jamais une écriture de champ.
 * L'interface se contente de ne PROPOSER que ce qui est plausible ; c'est le
 * serveur qui tranche et répond 409 s'il refuse la transition, message que
 * l'on réaffiche tel quel.
 *
 * Le statut de règlement (envoyée, partiellement payée, payée) se DÉDUIT des
 * encaissements enregistrés : il n'est donc affiché qu'en lecture, jamais
 * proposé au choix — un statut posé à la main mentirait sur la trésorerie.
 */
export function DocumentDetailSheet({
  documentId,
  onOpenChange,
  onEdit,
  onConverted,
}: {
  documentId: string | null;
  onOpenChange: (open: boolean) => void;
  onEdit: (document: Document) => void;
  /** Le document produit (facture ou avoir) devient la nouvelle cible du panneau. */
  onConverted: (document: Document) => void;
}) {
  const t = useTranslations("documents");
  const tStatus = useTranslations("status");
  const locale = useLocale();
  const queryClient = useQueryClient();
  const router = useRouter();

  const [confirm, setConfirm] = useState<"cancel" | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: documentKeys.detail(documentId ?? ""),
    queryFn: () => fetchDocument(documentId ?? ""),
    enabled: documentId !== null,
  });

  /** Rafraîchit la liste ET le détail : les deux caches parlent du même objet. */
  const settle = async (saved: Document) => {
    queryClient.setQueryData(documentKeys.detail(saved.id), saved);
    await queryClient.invalidateQueries({ queryKey: documentKeys.all });
    setConfirm(null);
    setActionError(null);
  };

  const fail = (cause: unknown) => {
    setActionError(toApiProblem(cause).detail ?? toApiProblem(cause).title);
    setConfirm(null);
  };

  const issueMutation = useMutation({
    mutationFn: (id: string) => issueDocument(id),
    onSuccess: settle,
    onError: fail,
  });

  const cancelMutation = useMutation({
    mutationFn: (id: string) => cancelDocument(id),
    onSuccess: settle,
    onError: fail,
  });

  const convertMutation = useMutation({
    mutationFn: (id: string) => convertDocument(id),
    onSuccess: async (created) => {
      await settle(created);
      onConverted(created);
    },
    onError: fail,
  });

  /**
   * Devis / facture → contrat de convention.
   *
   * `onConverted` ne s'applique PAS ici : ce transfert ne produit pas un
   * document, et le panneau n'a donc rien à rouvrir. On quitte l'écran pour
   * l'édition de la convention, qui naît incomplète par construction (titre
   * foncier, lots, délai) — c'est là que le travail commence.
   */
  const conventionMutation = useMutation({
    mutationFn: (id: string) => createConventionFromDocument(id),
    onSuccess: async (created) => {
      queryClient.setQueryData(conventionKeys.detail(created.id), created);
      await queryClient.invalidateQueries({ queryKey: conventionKeys.all });
      setActionError(null);
      router.push(`/conventions/${created.id}/edit`);
    },
    onError: fail,
  });

  const pending =
    issueMutation.isPending ||
    cancelMutation.isPending ||
    convertMutation.isPending ||
    conventionMutation.isPending;

  // Calculé ici et non dans le JSX : `data` y est déjà resserré par le ternaire
  // de chargement, et une seconde garde n'y ajouterait que du bruit.
  const printPath = data ? printRoute(data) : null;

  return (
    <>
      <Sheet
        open={documentId !== null}
        onOpenChange={(open) => {
          if (!open) setActionError(null);
          onOpenChange(open);
        }}
      >
        <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-2xl">
          {isPending ? (
            <div className="space-y-3 p-6">
              <Skeleton className="h-6 w-48" />
              <Skeleton className="h-4 w-32" />
              <Skeleton className="h-40 w-full" />
            </div>
          ) : isError || !data ? (
            <div className="p-6">
              <ErrorState
                detail={isError ? toApiProblem(error).detail : undefined}
                onRetry={() => void refetch()}
              />
            </div>
          ) : (
            <>
              <SheetHeader>
                <div className="flex items-center gap-2">
                  <SheetTitle className="font-heading">
                    {/* Un brouillon n'a pas encore de numéro : il n'en aura un
                        qu'à l'émission (§3). */}
                    {data.number ?? t("draftLabel")}
                  </SheetTitle>
                  <StatusBadge
                    status={data.status}
                    label={tStatus(data.status)}
                  />
                </div>
                <SheetDescription>
                  {data.clientName}
                  {data.parentNumber
                    ? ` · ${t("linkedTo", { number: data.parentNumber })}`
                    : ""}
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-5 px-4 pb-6">
                {actionError && (
                  <p className="text-destructive text-sm" role="alert">
                    {actionError}
                  </p>
                )}

                {/* -------------------------------------------- Actions */}
                <div className="flex flex-wrap gap-2">
                  {isIssuable(data) && (
                    <Button
                      size="sm"
                      disabled={pending || (data.items ?? []).length === 0}
                      onClick={() => issueMutation.mutate(data.id)}
                    >
                      <Send aria-hidden />
                      {t("actions.issue")}
                    </Button>
                  )}

                  {/* `isEditable` et non `status === "draft"` : la facture, la
                      situation et le devis restent modifiables une fois émis,
                      et le panneau doit offrir ce que la liste offre — sans
                      quoi la même pièce serait modifiable ici et gelée là. */}
                  {isEditable(data) && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => onEdit(data)}
                    >
                      {t("actions.edit")}
                    </Button>
                  )}

                  {/* Impression : disponible sur un BROUILLON comme sur un
                      document émis — une proposition commerciale circule avant
                      d'être numérotée. La page d'impression le signale. */}
                  {printPath !== null && (
                    <Button size="sm" variant="outline" asChild>
                      <Link href={printPath}>
                        <Printer aria-hidden />
                        {t("actions.print")}
                      </Link>
                    </Button>
                  )}

                  {isConvertible(data) && (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={pending}
                      onClick={() => convertMutation.mutate(data.id)}
                    >
                      <FileOutput aria-hidden />
                      {t("actions.convert")}
                    </Button>
                  )}

                  {isTransferableToConvention(data) && (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={pending}
                      onClick={() => conventionMutation.mutate(data.id)}
                    >
                      <FileSignature aria-hidden />
                      {t("actions.convention")}
                    </Button>
                  )}

                  {isCancellable(data) && (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={pending}
                      onClick={() => setConfirm("cancel")}
                    >
                      <Ban aria-hidden className="text-destructive" />
                      {t("actions.cancel")}
                    </Button>
                  )}
                </div>

                {/* --------------------------------------------- Entête */}
                <dl className="ring-border grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg px-3 py-2.5 text-sm ring-1">
                  <dt className="text-muted-foreground">{t("columns.issuedAt")}</dt>
                  <dd className="text-end">
                    {data.issuedAt ? formatDate(data.issuedAt, locale) : "—"}
                  </dd>
                  <dt className="text-muted-foreground">{t("columns.dueAt")}</dt>
                  <dd className="text-end">
                    {data.dueAt ? formatDate(data.dueAt, locale) : "—"}
                  </dd>
                  {data.clientIce && (
                    <>
                      <dt className="text-muted-foreground">{t("clientIce")}</dt>
                      <dd className="tabular text-end">{data.clientIce}</dd>
                    </>
                  )}
                </dl>

                {/* --------------------------------------------- Lignes */}
                <div className="ring-border overflow-hidden rounded-lg ring-1">
                  <div className="overflow-x-auto">
                    <table className="w-full border-collapse text-sm">
                      <thead>
                        <tr className="border-border bg-muted/40 text-muted-foreground border-b text-xs uppercase">
                          <th scope="col" className="px-3 py-2 text-start font-medium">
                            {t("form.line.label")}
                          </th>
                          <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t("form.line.quantity")}
                          </th>
                          <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t("form.line.unitPrice")}
                          </th>
                          <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t("form.line.tax")}
                          </th>
                          <th scope="col" className="px-3 py-2 text-end font-medium">
                            {t("form.line.total")}
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.items ?? []).map((item) => (
                          <tr
                            key={item.id}
                            className="border-border/60 border-b last:border-0"
                          >
                            <td className="px-3 py-2">
                              <span className="font-medium">{item.label}</span>
                              {item.description && (
                                <span className="text-muted-foreground block text-xs">
                                  {item.description}
                                </span>
                              )}
                            </td>
                            <td className="tabular px-3 py-2 text-end">
                              {item.quantity}
                              {item.unit ? ` ${item.unit}` : ""}
                            </td>
                            <td className="tabular px-3 py-2 text-end">
                              {formatMoney(
                                item.unitPriceCents,
                                locale,
                                data.currency,
                              )}
                            </td>
                            <td className="tabular px-3 py-2 text-end">
                              {item.taxRate} %
                            </td>
                            <td className="tabular px-3 py-2 text-end font-medium">
                              {formatMoney(
                                item.totalCents,
                                locale,
                                data.currency,
                              )}
                            </td>
                          </tr>
                        ))}
                        {(data.items ?? []).length === 0 && (
                          <tr>
                            <td
                              colSpan={5}
                              className="text-muted-foreground px-3 py-6 text-center"
                            >
                              {t("form.noLines")}
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>

                {/* --------------------------------- Récapitulatif de TVA */}
                {(data.taxSummary ?? []).length > 0 && (
                  <div>
                    <h3 className="mb-1.5 text-sm font-medium">
                      {t("totals.taxSummary")}
                    </h3>
                    <table className="ring-border w-full overflow-hidden rounded-lg text-sm ring-1">
                      <thead>
                        <tr className="border-border bg-muted/40 text-muted-foreground border-b text-xs uppercase">
                          <th scope="col" className="px-3 py-1.5 text-start font-medium">
                            {t("totals.rate")}
                          </th>
                          <th scope="col" className="px-3 py-1.5 text-end font-medium">
                            {t("totals.base")}
                          </th>
                          <th scope="col" className="px-3 py-1.5 text-end font-medium">
                            {t("totals.tax")}
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {(data.taxSummary ?? []).map((line) => (
                          <tr
                            key={line.rate}
                            className="border-border/60 border-b last:border-0"
                          >
                            <td className="tabular px-3 py-1.5">{line.rate} %</td>
                            <td className="tabular px-3 py-1.5 text-end">
                              {formatMoney(line.baseCents, locale, data.currency)}
                            </td>
                            <td className="tabular px-3 py-1.5 text-end">
                              {formatMoney(line.taxCents, locale, data.currency)}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {/* --------------------------------------------- Totaux */}
                <div className="flex justify-end">
                  <dl className="ring-border w-full max-w-72 space-y-1 rounded-lg px-3 py-2 text-sm ring-1">
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">
                        {t("totals.subtotal")}
                      </dt>
                      <dd className="tabular">
                        {formatMoney(data.subtotalCents, locale, data.currency)}
                      </dd>
                    </div>
                    {data.discountCents > 0 && (
                      <div className="flex justify-between">
                        <dt className="text-muted-foreground">
                          {t("totals.discount")}
                        </dt>
                        <dd className="tabular">
                          −{formatMoney(data.discountCents, locale, data.currency)}
                        </dd>
                      </div>
                    )}
                    <div className="flex justify-between">
                      <dt className="text-muted-foreground">{t("totals.tax")}</dt>
                      <dd className="tabular">
                        {formatMoney(data.taxCents, locale, data.currency)}
                      </dd>
                    </div>
                    <div className="border-border flex justify-between border-t pt-1 font-semibold">
                      <dt>{t("totals.total")}</dt>
                      <dd className="tabular">
                        {formatMoney(data.totalCents, locale, data.currency)}
                      </dd>
                    </div>
                  </dl>
                </div>

                {(data.notes || data.terms) && (
                  <div className="text-muted-foreground space-y-2 text-sm">
                    {data.notes && <p>{data.notes}</p>}
                    {data.terms && <p>{data.terms}</p>}
                  </div>
                )}
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>

      <ConfirmDialog
        open={confirm === "cancel"}
        onOpenChange={(open) => !open && setConfirm(null)}
        title={t("cancelConfirm.title")}
        description={t("cancelConfirm.description")}
        confirmLabel={t("cancelConfirm.confirm")}
        pending={cancelMutation.isPending}
        onConfirm={() => data && cancelMutation.mutate(data.id)}
      />
    </>
  );
}
