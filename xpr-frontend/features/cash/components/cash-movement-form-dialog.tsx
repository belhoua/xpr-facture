"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect, useMemo } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
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
  fetchCashCharges,
  createCashMovement,
  updateCashMovement,
} from "@/features/cash/api/cash";
import { dashboardKeys } from "@/features/dashboard/api/dashboard";
import {
  CASH_DIRECTIONS,
  PAYMENT_METHODS,
  cashMovementFormSchema,
  isEditableMethod,
  type CashMovement,
  type CashMovementFormValues,
} from "@/features/cash/schemas/cash";
import { REFERENCE_STALE_TIME } from "@/lib/api/stale-times";

const CURRENCIES = ["MAD", "EUR", "USD"] as const;


/**
 * Champs du formulaire mappables depuis une erreur de validation serveur.
 * `amountCents` en est absent : il n'existe pas côté formulaire (saisi via
 * sens + montant) — une erreur serveur dessus retombe sur le message global.
 */
const SERVER_FIELDS = [
  "partnerId",
  "occurredAt",
  "label",
  "charge",
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
    charge: "",
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
    charge: movement.charge ?? "",
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

  // Répertoire des tiers. TOUS les rôles depuis que la caisse porte les deux
  // sens (2026-08-25) : un décaissement se fait au profit d'un FOURNISSEUR, que
  // le filtre `client` masquait — on ne pouvait donc rattacher une sortie
  // qu'aux clients, ce qui n'a pas de sens.
  const partnerFilters = { type: "all" as const };
  const { data: partners } = useQuery({
    queryKey: partnerKeys.list(partnerFilters),
    queryFn: () => fetchPartners(partnerFilters),
  });

  // Le SENS pilote l'affichage du champ de charge. `useWatch` et non
  // `getValues` : celui-ci ne redéclenche aucun rendu, le champ n'apparaîtrait
  // qu'au prochain pour une autre raison.
  const direction = useWatch({ control: form.control, name: "direction" });

  // Natures de charge déjà employées. Chargées seulement quand le tiroir est
  // ouvert ET qu'il s'agit d'une sortie : sur un encaissement, le champ n'est
  // pas affiché et la requête ne servirait à rien.
  const { data: charges = [] } = useQuery({
    queryKey: cashKeys.charges(),
    queryFn: fetchCashCharges,
    enabled: open && direction === "outflow",
    staleTime: REFERENCE_STALE_TIME,
  });

  /**
   * Options du sélecteur de tiers.
   *
   * L'ICE accompagne la raison sociale : deux fiches d'un même groupe portent
   * souvent des noms très proches, et c'est l'identifiant qui les départage —
   * il entre d'ailleurs dans le champ de recherche du composant.
   */
  const partnerOptions = useMemo(
    () =>
      (partners?.data ?? []).map((partner) => ({
        value: partner.id,
        label: partner.legalName,
        hint: partner.ice ?? undefined,
      })),
    [partners],
  );

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
      // Le solde de caisse figure aussi sur le tableau de bord.
      await queryClient.invalidateQueries({ queryKey: dashboardKeys.all });
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
                  // Recherchable : le répertoire d'un cabinet compte des
                  // centaines de fiches, et un déroulant ne se parcourt qu'à la
                  // molette. Re-choisir la ligne déjà sélectionnée la retire —
                  // c'est ce qui remplace l'entrée « aucun tiers » du déroulant.
                  <Combobox
                    id="cash-partner"
                    options={partnerOptions}
                    value={field.value}
                    onChange={field.onChange}
                    placeholder={t("form.noClient")}
                    searchPlaceholder={t("form.searchClient")}
                    emptyLabel={t("form.noClientFound")}
                  />
                )}
              />
              <FieldError>{fieldError(errors.partnerId?.message)}</FieldError>
            </Field>

            {/* La CHARGE ne concerne qu'une sortie : classer une entrée
                d'argent en « Loyer » n'aurait pas de sens, et le serveur vide
                d'ailleurs le champ sur un encaissement. Il apparaît donc avec
                le sens choisi, plutôt que d'être désactivé — un champ grisé
                dont on ne sait pas ce qui l'active occupe la place sans rien
                apprendre. */}
            {direction === "outflow" && (
              <Field>
                <FieldLabel htmlFor="cash-charge">{t("form.charge")}</FieldLabel>
                {/* Un champ LIBRE avec suggestions, et non un `Combobox` :
                    celui-ci impose de choisir dans la liste, or une dépense
                    d'un genre nouveau ne doit pas attendre qu'on ait créé son
                    référentiel. `<datalist>` filtre à la frappe comme un
                    combobox, tout en laissant taper autre chose — et il est
                    natif, donc accessible sans un composant de plus. */}
                <Input
                  id="cash-charge"
                  list="cash-charge-options"
                  autoComplete="off"
                  placeholder={t("form.chargePlaceholder")}
                  aria-invalid={Boolean(errors.charge)}
                  {...form.register("charge")}
                />
                <datalist id="cash-charge-options">
                  {charges.map((charge) => (
                    <option key={charge} value={charge} />
                  ))}
                </datalist>
                <p className="text-muted-foreground text-xs">
                  {t("form.chargeHint")}
                </p>
                <FieldError>{fieldError(errors.charge?.message)}</FieldError>
              </Field>
            )}

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
