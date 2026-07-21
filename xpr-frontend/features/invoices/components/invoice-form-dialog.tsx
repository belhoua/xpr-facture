"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect } from "react";
import { Controller, useForm } from "react-hook-form";

import { StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import { createInvoice, invoiceKeys, updateInvoice } from "@/features/invoices/api/invoices";
import {
  INVOICE_FORM_STATUSES,
  invoiceFormSchema,
  type Invoice,
  type InvoiceFormValues,
} from "@/features/invoices/schemas/invoice";

const CURRENCIES = ["MAD", "EUR", "USD"] as const;

/** Valeur sentinelle du select : Radix refuse une SelectItem de valeur vide. */
const WALK_IN = "__walk_in__";

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = ["partnerId", "clientName", "issuedAt", "dueAt", "status", "currency"] as const;

function emptyValues(): InvoiceFormValues {
  return {
    partnerId: "",
    clientName: "",
    issuedAt: "",
    dueAt: "",
    status: "draft",
    amount: 0,
    currency: "MAD",
  };
}

/** Pré-remplit le formulaire depuis une facture existante (mode édition). */
function valuesFromInvoice(invoice: Invoice): InvoiceFormValues {
  return {
    partnerId: invoice.partnerId ?? "",
    clientName: invoice.clientName,
    issuedAt: invoice.issuedAt ?? "",
    dueAt: invoice.dueAt ?? "",
    // Le statut peut ne plus être sélectionnable (ex. cancelled) : on retombe
    // sur draft, mais en pratique seuls les brouillons arrivent ici (§3).
    status: INVOICE_FORM_STATUSES.includes(
      invoice.status as (typeof INVOICE_FORM_STATUSES)[number],
    )
      ? (invoice.status as InvoiceFormValues["status"])
      : "draft",
    amount: invoice.totalCents / 100,
    currency: invoice.currency,
  };
}

/**
 * Formulaire de création / d'édition d'une facture. Un seul composant pour les
 * deux modes : la présence d'`invoice` bascule le titre, les valeurs initiales
 * et l'appel API. En succès on invalide la liste plutôt que de la patcher —
 * le serveur reste l'autorité (numéro attribué, statut recalculé).
 */
export function InvoiceFormDialog({
  open,
  onOpenChange,
  invoice,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  invoice?: Invoice | null;
}) {
  const t = useTranslations("invoices");
  const tStatus = useTranslations("status");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(invoice);

  // Répertoire des clients pour le sélecteur. `enabled: open` : on ne charge
  // pas le répertoire tant que la boîte de dialogue est fermée.
  const { data: partners } = useQuery({
    queryKey: partnerKeys.list({ type: "client" }),
    queryFn: () => fetchPartners({ type: "client" }),
    enabled: open,
  });

  const clients = partners?.data ?? [];

  const form = useForm<InvoiceFormValues>({
    resolver: zodResolver(invoiceFormSchema),
    defaultValues: emptyValues(),
  });

  // Réinitialise à chaque ouverture : le même composant sert des factures
  // différentes, un état résiduel afficherait les valeurs de la précédente.
  useEffect(() => {
    if (open) {
      form.reset(invoice ? valuesFromInvoice(invoice) : emptyValues());
    }
  }, [open, invoice, form]);

  const mutation = useMutation({
    mutationFn: (values: InvoiceFormValues) =>
      isEdit && invoice
        ? updateInvoice(invoice.id, values)
        : createInvoice(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: invoiceKeys.all });
      onOpenChange(false);
    },
    onError: (error) => {
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

  const selectedPartnerId = form.watch("partnerId");
  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("form.editTitle") : t("form.createTitle")}
          </DialogTitle>
          <DialogDescription>
            {isEdit ? t("form.editDescription") : t("form.createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form
          id="invoice-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            <Field>
              <FieldLabel htmlFor="invoice-partner">
                {t("form.partner")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="partnerId"
                render={({ field }) => (
                  <Select
                    value={field.value === "" ? WALK_IN : field.value}
                    onValueChange={(value) =>
                      field.onChange(value === WALK_IN ? "" : value)
                    }
                  >
                    <SelectTrigger id="invoice-partner">
                      <SelectValue placeholder={t("form.partnerPlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {/* Radix interdit une SelectItem de valeur "" : on utilise
                          une sentinelle, retraduite en "" à la sélection. */}
                      <SelectItem value={WALK_IN}>
                        {t("form.walkInClient")}
                      </SelectItem>
                      {clients.map((partner) => (
                        <SelectItem key={partner.id} value={partner.id}>
                          {partner.legalName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{fieldError(errors.partnerId?.message)}</FieldError>
            </Field>

            {/* Nom libre affiché UNIQUEMENT pour un client de passage : avec un
                tiers, le serveur fige sa raison sociale et la saisie n'aurait
                aucun effet — un champ inopérant induit en erreur. */}
            {selectedPartnerId === "" && (
              <Field>
                <FieldLabel htmlFor="invoice-client">
                  {t("form.clientName")}
                </FieldLabel>
                <Input
                  id="invoice-client"
                  placeholder={t("form.clientPlaceholder")}
                  aria-invalid={Boolean(errors.clientName)}
                  {...form.register("clientName")}
                />
                <FieldError>{fieldError(errors.clientName?.message)}</FieldError>
              </Field>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="invoice-issued">
                  {t("form.issuedAt")}
                </FieldLabel>
                <Input
                  id="invoice-issued"
                  type="date"
                  aria-invalid={Boolean(errors.issuedAt)}
                  {...form.register("issuedAt")}
                />
                <FieldError>{fieldError(errors.issuedAt?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="invoice-due">{t("form.dueAt")}</FieldLabel>
                <Input
                  id="invoice-due"
                  type="date"
                  aria-invalid={Boolean(errors.dueAt)}
                  {...form.register("dueAt")}
                />
                <FieldError>{fieldError(errors.dueAt?.message)}</FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="invoice-amount">
                  {t("form.amount")}
                </FieldLabel>
                <Input
                  id="invoice-amount"
                  type="number"
                  min={0}
                  step="0.01"
                  inputMode="decimal"
                  aria-invalid={Boolean(errors.amount)}
                  {...form.register("amount", { valueAsNumber: true })}
                />
                <FieldError>{fieldError(errors.amount?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="invoice-currency">
                  {t("form.currency")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="currency"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="invoice-currency">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {CURRENCIES.map((code) => (
                          <SelectItem key={code} value={code}>
                            {code}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.currency?.message)}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="invoice-status">
                {t("form.status")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="status"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="invoice-status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {INVOICE_FORM_STATUSES.map((value) => (
                        <SelectItem key={value} value={value}>
                          <StatusBadge status={value} label={tStatus(value)} />
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{fieldError(errors.status?.message)}</FieldError>
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={mutation.isPending}
          >
            {tRoot("common.cancel")}
          </Button>
          <Button type="submit" form="invoice-form" disabled={mutation.isPending}>
            {mutation.isPending ? t("form.submitting") : t("form.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
