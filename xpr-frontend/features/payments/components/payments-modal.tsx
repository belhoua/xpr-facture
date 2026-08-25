"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Paperclip, Plus, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { cashKeys } from "@/features/cash/api/cash";
import { dashboardKeys } from "@/features/dashboard/api/dashboard";
import { documentKeys } from "@/features/documents/api/documents";
import {
  createPayment,
  deletePayment,
  fetchPayments,
  paymentKeys,
} from "@/features/payments/api/payments";
import {
  CHECK_STATUSES,
  PAYMENT_METHODS,
  isBankInstrument,
  paymentFormSchema,
  type Payment,
  type PaymentFormValues,
} from "@/features/payments/schemas/payment";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";

/** Aujourd'hui en ISO : la date du jour est le cas nominal d'un encaissement. */
function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function emptyValues(): PaymentFormValues {
  return {
    amount: 0,
    paidOn: todayIso(),
    method: "cash",
    reference: "",
    notes: "",
    checkNumber: "",
    checkStatus: "pending",
    bankDepositDate: "",
    receivedDate: "",
  };
}

/**
 * Règlements d'une FACTURE : trois indicateurs, l'historique, un formulaire.
 *
 * Réservée aux factures, et l'écran le garantit en amont — l'icône n'apparaît
 * pas ailleurs. Le serveur le garantit à son tour : un devis reçoit 409. Une
 * proposition commerciale n'ouvre aucune créance, il n'y a rien à solder.
 *
 * Les trois cumuls viennent du SERVEUR et ne sont pas recalculés ici : « reste
 * à payer » est une soustraction que chaque écran réécrirait à sa façon, et un
 * `max(0, …)` oublié afficherait un solde négatif sur un trop-perçu.
 */
export function PaymentsModal({
  invoiceId,
  invoiceNumber,
  open,
  onOpenChange,
}: {
  invoiceId: string | null;
  invoiceNumber: string | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const t = useTranslations("payments");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [scan, setScan] = useState<File | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Payment | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const form = useForm<PaymentFormValues>({
    resolver: zodResolver(paymentFormSchema),
    defaultValues: emptyValues(),
  });

  const method = useWatch({ control: form.control, name: "method" });
  const showBankFields = isBankInstrument(method);

  const query = useQuery({
    queryKey: paymentKeys.ofInvoice(invoiceId ?? ""),
    queryFn: () => fetchPayments(invoiceId ?? ""),
    enabled: open && invoiceId !== null,
  });

  /**
   * Après chaque écriture, TROIS caches sont invalidés.
   *
   *  - les RÈGLEMENTS, évidemment ;
   *  - les DOCUMENTS : le statut et le solde de la facture viennent de changer,
   *    et la liste restée ouverte derrière la modale afficherait sinon un badge
   *    périmé ;
   *  - la CAISSE : le règlement y écrit son mouvement (`PaymentCashMirror`),
   *    donc le total encaissé et le solde net de l'écran Caisses changent à
   *    l'instant même ;
   *  - le TABLEAU DE BORD (2026-08-26). Ses cartes « encaissé » et « restant
   *    dû » se lisent sur les règlements, et « solde caisse » sur le mouvement
   *    que celui-ci vient d'écrire. Une seule requête les alimente toutes :
   *    invalider la racine suffit à remettre l'écran d'accueil d'aplomb.
   *
   * Invalidé depuis ICI et non depuis l'écran Caisses : c'est l'écriture qui
   * sait ce qu'elle vient de changer. Un écran ne peut pas deviner qu'une
   * modale ouverte ailleurs a modifié ce qu'il affiche.
   */
  const settle = async () => {
    await queryClient.invalidateQueries({ queryKey: paymentKeys.all });
    await queryClient.invalidateQueries({ queryKey: documentKeys.all });
    await queryClient.invalidateQueries({ queryKey: cashKeys.all });
    await queryClient.invalidateQueries({ queryKey: dashboardKeys.all });
    setActionError(null);
  };

  const fail = (cause: unknown) => {
    const problem = toApiProblem(cause);

    // Les erreurs de CHAMP retournent dans le formulaire ; le reste (409 sur
    // une facture annulée, par exemple) s'affiche en tête de modale.
    if (problem.errors) {
      for (const [field, messages] of Object.entries(problem.errors)) {
        form.setError(field as keyof PaymentFormValues, {
          message: messages[0],
        });
      }

      return;
    }

    setActionError(problem.detail ?? problem.title);
  };

  const createMutation = useMutation({
    mutationFn: (values: PaymentFormValues) =>
      createPayment(invoiceId ?? "", values, scan),
    onSuccess: async () => {
      await settle();
      form.reset(emptyValues());
      setScan(null);
    },
    onError: fail,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deletePayment(id),
    onSuccess: async () => {
      await settle();
      setDeleteTarget(null);
    },
    onError: (cause) => {
      setDeleteTarget(null);
      fail(cause);
    },
  });

  const summary = query.data?.summary;
  const payments = query.data?.data ?? [];
  const currency = summary?.currency ?? "MAD";

  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? t(message.slice(11)) : message;

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-4xl">
          <DialogHeader>
            <DialogTitle>{t("title")}</DialogTitle>
            <DialogDescription>
              {invoiceNumber ?? t("untitledInvoice")}
            </DialogDescription>
          </DialogHeader>

          {query.isPending ? (
            <div className="space-y-3 py-4">
              <Skeleton className="h-20 w-full" />
              <Skeleton className="h-32 w-full" />
            </div>
          ) : query.isError ? (
            <ErrorState
              detail={toApiProblem(query.error).detail}
              onRetry={() => void query.refetch()}
            />
          ) : (
            <div className="max-h-[70vh] space-y-6 overflow-y-auto pe-1">
              {/* Trois indicateurs, dans l'ordre de lecture d'un solde : ce qui
                  est dû, ce qui est rentré, ce qui reste. La couleur porte le
                  sens (§11) — neutre, positif, en attente. */}
              <div className="grid gap-3 sm:grid-cols-3">
                <SummaryTile
                  label={t("summary.total")}
                  value={formatMoney(summary?.totalCents ?? 0, locale, currency)}
                  tone="neutral"
                />
                <SummaryTile
                  label={t("summary.paid")}
                  value={formatMoney(summary?.paidCents ?? 0, locale, currency)}
                  tone="positive"
                />
                <SummaryTile
                  label={t("summary.remaining")}
                  value={formatMoney(
                    summary?.remainingCents ?? 0,
                    locale,
                    currency,
                  )}
                  tone="pending"
                />
              </div>

              {actionError ? (
                <p className="text-destructive text-sm">{actionError}</p>
              ) : null}

              <PaymentHistory
                payments={payments}
                locale={locale}
                currency={currency}
                onDelete={setDeleteTarget}
                pending={deleteMutation.isPending}
              />

              {/* ── Ajouter un règlement ─────────────────────────────── */}
              <form
                onSubmit={form.handleSubmit((values) =>
                  createMutation.mutate(values),
                )}
                className="border-border space-y-4 border-t pt-4"
              >
                <h3 className="text-sm font-medium">{t("form.title")}</h3>

                <div className="grid gap-4 sm:grid-cols-3">
                  <Field>
                    <FieldLabel htmlFor="payment-amount">
                      {t("form.amount")} *
                    </FieldLabel>
                    <Input
                      id="payment-amount"
                      type="number"
                      step="0.01"
                      min="0"
                      aria-invalid={Boolean(form.formState.errors.amount)}
                      {...form.register("amount", { valueAsNumber: true })}
                    />
                    <FieldError>
                      {fieldError(form.formState.errors.amount?.message)}
                    </FieldError>
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="payment-date">
                      {t("form.date")} *
                    </FieldLabel>
                    <Input
                      id="payment-date"
                      type="date"
                      aria-invalid={Boolean(form.formState.errors.paidOn)}
                      {...form.register("paidOn")}
                    />
                    <FieldError>
                      {fieldError(form.formState.errors.paidOn?.message)}
                    </FieldError>
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="payment-method">
                      {t("form.method")} *
                    </FieldLabel>
                    <Controller
                      control={form.control}
                      name="method"
                      render={({ field }) => (
                        <Select
                          value={field.value}
                          onValueChange={field.onChange}
                        >
                          <SelectTrigger id="payment-method">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {PAYMENT_METHODS.map((value) => (
                              <SelectItem key={value} value={value}>
                                {t(`methods.${value}`)}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                </div>

                {/* Champs d'EFFET BANCAIRE : ils n'apparaissent que sur un
                    chèque ou une LCN. Les afficher toujours, grisés, encombrerait
                    le formulaire du cas le plus courant — l'espèce et le
                    virement, qui sont soldés à la saisie. */}
                {showBankFields ? (
                  <div className="bg-muted/40 grid gap-4 rounded-md p-3 sm:grid-cols-2">
                    <Field>
                      <FieldLabel htmlFor="payment-check-number">
                        {t("form.checkNumber")} *
                      </FieldLabel>
                      <Input
                        id="payment-check-number"
                        aria-invalid={Boolean(
                          form.formState.errors.checkNumber,
                        )}
                        {...form.register("checkNumber")}
                      />
                      <FieldError>
                        {fieldError(form.formState.errors.checkNumber?.message)}
                      </FieldError>
                    </Field>

                    <Field>
                      <FieldLabel htmlFor="payment-check-status">
                        {t("form.checkStatus")} *
                      </FieldLabel>
                      <Controller
                        control={form.control}
                        name="checkStatus"
                        render={({ field }) => (
                          <Select
                            value={field.value}
                            onValueChange={field.onChange}
                          >
                            <SelectTrigger id="payment-check-status">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              {CHECK_STATUSES.map((value) => (
                                <SelectItem key={value} value={value}>
                                  {t(`checkStatuses.${value}`)}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        )}
                      />
                    </Field>

                    <Field>
                      <FieldLabel htmlFor="payment-received">
                        {t("form.receivedDate")}
                      </FieldLabel>
                      <Input
                        id="payment-received"
                        type="date"
                        aria-invalid={Boolean(
                          form.formState.errors.receivedDate,
                        )}
                        {...form.register("receivedDate")}
                      />
                      <FieldError>
                        {fieldError(
                          form.formState.errors.receivedDate?.message,
                        )}
                      </FieldError>
                    </Field>

                    <Field>
                      <FieldLabel htmlFor="payment-deposit">
                        {t("form.bankDepositDate")}
                      </FieldLabel>
                      <Input
                        id="payment-deposit"
                        type="date"
                        {...form.register("bankDepositDate")}
                      />
                    </Field>

                    <Field className="sm:col-span-2">
                      <FieldLabel htmlFor="payment-scan">
                        {t("form.scan")}
                      </FieldLabel>
                      <Input
                        id="payment-scan"
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        onChange={(event) =>
                          setScan(event.target.files?.[0] ?? null)
                        }
                      />
                      <p className="text-muted-foreground text-xs">
                        {t("form.scanHint")}
                      </p>
                    </Field>
                  </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                  <Field>
                    <FieldLabel htmlFor="payment-reference">
                      {t("form.reference")}
                    </FieldLabel>
                    <Input id="payment-reference" {...form.register("reference")} />
                  </Field>

                  <Field>
                    <FieldLabel htmlFor="payment-notes">
                      {t("form.notes")}
                    </FieldLabel>
                    <Textarea
                      id="payment-notes"
                      rows={2}
                      {...form.register("notes")}
                    />
                  </Field>
                </div>

                <div className="flex justify-end">
                  <Button type="submit" loading={createMutation.isPending}>
                    <Plus className="size-4" aria-hidden />
                    {createMutation.isPending ? t("form.saving") : t("form.submit")}
                  </Button>
                </div>
              </form>
            </div>
          )}
        </DialogContent>
      </Dialog>

      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(next) => !next && setDeleteTarget(null)}
        title={t("delete.title")}
        description={t("delete.description")}
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />
    </>
  );
}

/** Tuile d'indicateur. La couleur porte le sens, jamais elle seule (§11). */
function SummaryTile({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone: "neutral" | "positive" | "pending";
}) {
  const tones = {
    neutral: "bg-muted/50 text-foreground",
    positive:
      "bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200",
    pending:
      "bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200",
  } as const;

  return (
    <div className={`rounded-md px-3 py-2 ${tones[tone]}`}>
      <p className="text-xs opacity-80">{label}</p>
      <p className="amount mt-0.5 text-lg font-semibold">{value}</p>
    </div>
  );
}

/** Historique, du plus récent au plus ancien — l'ordre vient du serveur. */
function PaymentHistory({
  payments,
  locale,
  currency,
  onDelete,
  pending,
}: {
  payments: readonly Payment[];
  locale: string;
  currency: string;
  onDelete: (payment: Payment) => void;
  pending: boolean;
}) {
  const t = useTranslations("payments");

  if (payments.length === 0) {
    return (
      <p className="text-muted-foreground py-6 text-center text-sm">
        {t("empty")}
      </p>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="text-muted-foreground border-border border-b text-xs uppercase">
          <tr>
            <th className="py-2 text-start font-medium">{t("columns.date")}</th>
            <th className="py-2 text-end font-medium">{t("columns.amount")}</th>
            <th className="py-2 text-start font-medium">{t("columns.method")}</th>
            <th className="py-2 text-start font-medium">
              {t("columns.reference")}
            </th>
            <th className="py-2 text-start font-medium">{t("columns.notes")}</th>
            <th className="py-2 text-end font-medium">
              <span className="sr-only">{t("columns.actions")}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          {payments.map((payment) => (
            <tr key={payment.id} className="border-border/60 border-b">
              <td className="py-2">{formatDate(payment.paidOn, locale)}</td>
              <td className="amount py-2 text-end font-medium">
                {formatMoney(payment.amountCents, locale, currency)}
              </td>
              <td className="py-2">
                {t(`methods.${payment.method}`)}
                {/* Le statut d'un effet compte autant que son mode : un chèque
                    « rejeté » reste enregistré, et c'est ce qui explique un
                    solde qui ne bouge pas. */}
                {payment.checkStatus ? (
                  <span className="text-muted-foreground">
                    {" — "}
                    {t(`checkStatuses.${payment.checkStatus}`)}
                  </span>
                ) : null}
              </td>
              <td className="py-2">
                {payment.checkNumber ?? payment.reference ?? "—"}
                {payment.scanUrl ? (
                  <a
                    href={payment.scanUrl}
                    target="_blank"
                    rel="noreferrer"
                    className="text-muted-foreground hover:text-foreground ms-1 inline-flex align-middle"
                    aria-label={t("columns.scan")}
                  >
                    <Paperclip className="size-3.5" aria-hidden />
                  </a>
                ) : null}
              </td>
              <td className="text-muted-foreground max-w-[16rem] truncate py-2">
                {payment.notes ?? "—"}
              </td>
              <td className="py-2 text-end">
                <Button
                  variant="ghost"
                  size="icon-sm"
                  disabled={pending}
                  onClick={() => onDelete(payment)}
                  aria-label={t("delete.action")}
                >
                  <Trash2 className="text-destructive size-4" aria-hidden />
                </Button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
