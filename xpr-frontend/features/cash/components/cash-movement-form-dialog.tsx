"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect } from "react";
import { Controller, useForm } from "react-hook-form";

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
import {
  cashKeys,
  createCashMovement,
  updateCashMovement,
} from "@/features/cash/api/cash";
import {
  CASH_DIRECTIONS,
  PAYMENT_METHODS,
  cashMovementFormSchema,
  isEditableMethod,
  type CashMovement,
  type CashMovementFormValues,
} from "@/features/cash/schemas/cash";

const CURRENCIES = ["MAD", "EUR", "USD"] as const;

/** Valeur d'item pour « aucun tiers » : Radix interdit la chaîne vide. */
const NO_PARTNER = "__none__";

/**
 * Champs du formulaire mappables depuis une erreur de validation serveur.
 * `amountCents` en est absent : il n'existe pas côté formulaire (saisi via
 * sens + montant) — une erreur serveur dessus retombe sur le message global.
 */
const SERVER_FIELDS = [
  "partnerId",
  "occurredAt",
  "label",
  "method",
  "registerName",
  "currency",
] as const;

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

function emptyValues(): CashMovementFormValues {
  return {
    partnerId: "",
    occurredAt: todayIso(),
    label: "",
    method: "cash",
    registerName: "",
    direction: "inflow",
    amount: 0,
    currency: "MAD",
  };
}

/** Décompose le montant signé de l'API en (sens, montant positif). */
function valuesFromMovement(movement: CashMovement): CashMovementFormValues {
  return {
    // L'API renvoie `null` pour un mouvement sans tiers ; le Select manipule "".
    partnerId: movement.partnerId ?? "",
    occurredAt: movement.occurredAt,
    label: movement.label,
    // Seule une écriture SAISIE parvient ici — l'écran n'ouvre l'édition que
    // sur `source === "cash"`. Les deux replis couvrent le typage d'une ligne
    // de règlement, qui peut porter un mode et une caisse que le formulaire ne
    // sait pas représenter ; ils ne décrivent aucun cas atteignable.
    method: isEditableMethod(movement.method) ? movement.method : "cash",
    registerName: movement.registerName ?? "",
    direction: movement.amountCents < 0 ? "outflow" : "inflow",
    amount: Math.abs(movement.amountCents) / 100,
    currency: movement.currency,
  };
}

/**
 * Formulaire de création / d'édition d'un mouvement de caisse. Un seul
 * composant pour les deux modes ; en succès on invalide le résumé plutôt que
 * de le patcher — les soldes agrégés se recalculent côté serveur.
 */
export function CashMovementFormDialog({
  open,
  onOpenChange,
  movement,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  movement?: CashMovement | null;
}) {
  const t = useTranslations("cash");
  const tMethods = useTranslations("cash.methods");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(movement);

  const form = useForm<CashMovementFormValues>({
    resolver: zodResolver(cashMovementFormSchema),
    defaultValues: emptyValues(),
  });

  // Répertoire des tiers pour le déroulant. `client` seulement : la caisse de
  // cet écran suit les encaissements, et proposer les fournisseurs allongerait
  // la liste de noms qu'on n'y cherche pas.
  const partnerFilters = { type: "client" as const };
  const { data: partners } = useQuery({
    queryKey: partnerKeys.list(partnerFilters),
    queryFn: () => fetchPartners(partnerFilters),
  });

  useEffect(() => {
    if (open) {
      form.reset(movement ? valuesFromMovement(movement) : emptyValues());
    }
  }, [open, movement, form]);

  const mutation = useMutation({
    mutationFn: (values: CashMovementFormValues) =>
      isEdit && movement
        ? updateCashMovement(movement.id, values)
        : createCashMovement(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: cashKeys.all });
      onOpenChange(false);
    },
    onError: (error) => {
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

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
          id="cash-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            {/* Le TIERS d'abord : c'est lui qui donne son sens à l'écriture,
                et le libellé se rédige plus vite une fois le client choisi.
                Facultatif — un loyer ou un achat de fournitures n'en a pas. */}
            <Field>
              <FieldLabel htmlFor="cash-partner">{t("form.client")}</FieldLabel>
              <Controller
                control={form.control}
                name="partnerId"
                render={({ field }) => (
                  <Select
                    // Radix refuse la chaîne vide comme valeur d'item : le
                    // sentinel NO_PARTNER porte « aucun tiers » dans la liste,
                    // et redevient "" dans le formulaire.
                    value={field.value === "" ? NO_PARTNER : field.value}
                    onValueChange={(value) =>
                      field.onChange(value === NO_PARTNER ? "" : value)
                    }
                  >
                    <SelectTrigger id="cash-partner" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_PARTNER}>
                        {t("form.noClient")}
                      </SelectItem>
                      {(partners?.data ?? []).map((partner) => (
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

            <Field>
              <FieldLabel htmlFor="cash-label">{t("form.label")}</FieldLabel>
              <Input
                id="cash-label"
                placeholder={t("form.labelPlaceholder")}
                aria-invalid={Boolean(errors.label)}
                {...form.register("label")}
              />
              <FieldError>{fieldError(errors.label?.message)}</FieldError>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="cash-date">{t("form.date")}</FieldLabel>
                <Input
                  id="cash-date"
                  type="date"
                  aria-invalid={Boolean(errors.occurredAt)}
                  {...form.register("occurredAt")}
                />
                <FieldError>{fieldError(errors.occurredAt?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="cash-register">
                  {t("form.register")}
                </FieldLabel>
                <Input
                  id="cash-register"
                  placeholder={t("form.registerPlaceholder")}
                  aria-invalid={Boolean(errors.registerName)}
                  {...form.register("registerName")}
                />
                <FieldError>
                  {fieldError(errors.registerName?.message)}
                </FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="cash-direction">
                  {t("form.direction")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="direction"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="cash-direction">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {CASH_DIRECTIONS.map((value) => (
                          <SelectItem key={value} value={value}>
                            {t(`direction.${value}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.direction?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="cash-method">{t("form.method")}</FieldLabel>
                <Controller
                  control={form.control}
                  name="method"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="cash-method">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {PAYMENT_METHODS.map((value) => (
                          <SelectItem key={value} value={value}>
                            {tMethods(value)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.method?.message)}</FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="cash-amount">{t("form.amount")}</FieldLabel>
                <Input
                  id="cash-amount"
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
                <FieldLabel htmlFor="cash-currency">
                  {t("form.currency")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="currency"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="cash-currency">
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
          <Button type="submit" form="cash-form" loading={mutation.isPending}>
            {mutation.isPending ? t("form.submitting") : t("form.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
