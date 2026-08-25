"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";

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
import { Textarea } from "@/components/ui/textarea";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import {
  conventionKeys,
  createDeposit,
  depositKeys,
  fetchConventions,
  updateDeposit,
} from "@/features/conventions/api/conventions";
import {
  DEPOSIT_STATUSES,
  depositFormSchema,
  emptyDeposit,
  toDepositFormValues,
  type DepositFormValues,
  type FileDeposit,
} from "@/features/conventions/schemas/convention";
import { REFERENCE_STALE_TIME } from "@/lib/api/stale-times";


/** Champs que le serveur nomme à l'identique (erreurs RFC 9457). */
const SERVER_FIELDS = [
  "reference",
  "depositedAt",
  "organisation",
  "status",
  "decidedAt",
  "notes",
] as const;

/**
 * Enregistrement et correction d'un dépôt de dossier.
 *
 * Une boîte de dialogue et non une page, contrairement à la convention : cinq
 * champs qu'on saisit en revenant du guichet, sans quitter la liste où l'on
 * vient de constater qu'un dossier manquait.
 *
 * La date de décision n'apparaît QUE sur un dossier tranché : la demander sur un
 * dossier « en cours » inviterait à dater ce qui n'a pas eu lieu, et le serveur
 * l'effacerait de toute façon.
 */
export function DepositFormDialog({
  open,
  onOpenChange,
  /**
   * Convention instruite, quand elle est connue du contexte (fiche d'une
   * convention). Vide depuis l'écran transverse des dépôts : le champ apparaît
   * alors dans le formulaire.
   */
  conventionId = "",
  /** Référence proposée par défaut : le n° de dossier déjà connu du contrat. */
  defaultReference = "",
  deposit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  conventionId?: string;
  defaultReference?: string;
  deposit?: FileDeposit | null;
}) {
  const t = useTranslations("deposits");
  const tCommon = useTranslations("common");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(deposit);
  const picksConvention = conventionId === "" && ! isEdit;

  // Chargée UNIQUEMENT quand le formulaire doit faire choisir : sur la fiche
  // d'une convention, la liste complète serait une requête pour rien.
  const conventionsQuery = useQuery({
    queryKey: conventionKeys.list({ search: "", status: "all" }),
    queryFn: () => fetchConventions({ search: "", status: "all" }),
    enabled: open && picksConvention,
    staleTime: REFERENCE_STALE_TIME,
  });

  const form = useForm<DepositFormValues>({
    resolver: zodResolver(depositFormSchema),
    defaultValues: emptyDeposit(conventionId, defaultReference),
  });

  // La boîte est montée en permanence : sans cette réinitialisation, elle
  // rouvrirait sur la saisie précédente.
  useEffect(() => {
    if (open) {
      form.reset(
        deposit
          ? toDepositFormValues(deposit)
          : emptyDeposit(conventionId, defaultReference),
      );
    }
  }, [open, deposit, conventionId, defaultReference, form]);

  const status = useWatch({ control: form.control, name: "status" });
  const isDecided = status === "validated" || status === "rejected";

  const mutation = useMutation({
    mutationFn: (values: DepositFormValues) =>
      isEdit && deposit
        ? updateDeposit(deposit.id, values)
        : createDeposit(values),
    onSuccess: async () => {
      // Les DEUX familles sont invalidées : le premier dépôt donne son numéro à
      // la convention, dont la fiche affiche ce numéro. N'invalider que les
      // dépôts laisserait le contrat afficher « aucun dossier » alors qu'il en
      // a un.
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: depositKeys.all }),
        queryClient.invalidateQueries({ queryKey: conventionKeys.all }),
      ]);
      onOpenChange(false);
    },
    onError: (error) => {
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

  const errors = form.formState.errors;
  const message = (key?: string): string | undefined =>
    key?.startsWith("validation.") ? tRoot(key) : key;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("form.editTitle") : t("form.createTitle")}
          </DialogTitle>
          <DialogDescription>{t("form.description")}</DialogDescription>
        </DialogHeader>

        <form
          id="deposit-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          noValidate
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            {picksConvention ? (
              <Field data-invalid={errors.conventionId ? true : undefined}>
                <FieldLabel htmlFor="deposit-convention">
                  {t("form.convention")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="conventionId"
                  render={({ field }) => (
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={conventionsQuery.isPending}
                    >
                      <SelectTrigger id="deposit-convention" className="w-full">
                        <SelectValue placeholder={t("form.conventionPlaceholder")} />
                      </SelectTrigger>
                      <SelectContent>
                        {(conventionsQuery.data?.data ?? []).map((convention) => (
                          <SelectItem key={convention.id} value={convention.id}>
                            {convention.ownerName} — {convention.projectDescription}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError
                  errors={[{ message: message(errors.conventionId?.message) }]}
                />
              </Field>
            ) : null}

            <Field data-invalid={errors.reference ? true : undefined}>
              <FieldLabel htmlFor="deposit-reference">
                {t("form.reference")}
              </FieldLabel>
              <Input
                id="deposit-reference"
                placeholder="0003439/AK/26"
                {...form.register("reference")}
                aria-invalid={errors.reference ? true : undefined}
              />
              <FieldError errors={[{ message: message(errors.reference?.message) }]} />
            </Field>

            <Field data-invalid={errors.organisation ? true : undefined}>
              <FieldLabel htmlFor="deposit-organisation">
                {t("form.organisation")}
              </FieldLabel>
              <Input
                id="deposit-organisation"
                placeholder={t("form.organisationPlaceholder")}
                {...form.register("organisation")}
                aria-invalid={errors.organisation ? true : undefined}
              />
              <FieldError
                errors={[{ message: message(errors.organisation?.message) }]}
              />
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field data-invalid={errors.depositedAt ? true : undefined}>
                <FieldLabel htmlFor="deposit-date">{t("form.depositedAt")}</FieldLabel>
                <Input
                  id="deposit-date"
                  type="date"
                  {...form.register("depositedAt")}
                  aria-invalid={errors.depositedAt ? true : undefined}
                />
                <FieldError
                  errors={[{ message: message(errors.depositedAt?.message) }]}
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="deposit-status">{t("form.status")}</FieldLabel>
                <Controller
                  control={form.control}
                  name="status"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="deposit-status" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {DEPOSIT_STATUSES.map((value) => (
                          <SelectItem key={value} value={value}>
                            {t(`status.${value}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </Field>
            </div>

            {isDecided ? (
              <Field data-invalid={errors.decidedAt ? true : undefined}>
                <FieldLabel htmlFor="deposit-decided">
                  {t("form.decidedAt")}
                </FieldLabel>
                <Input
                  id="deposit-decided"
                  type="date"
                  {...form.register("decidedAt")}
                  aria-invalid={errors.decidedAt ? true : undefined}
                />
                <FieldError
                  errors={[{ message: message(errors.decidedAt?.message) }]}
                />
              </Field>
            ) : null}

            <Field>
              <FieldLabel htmlFor="deposit-notes">{t("form.notes")}</FieldLabel>
              <Textarea id="deposit-notes" rows={3} {...form.register("notes")} />
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {tCommon("cancel")}
          </Button>
          <Button type="submit" form="deposit-form" loading={mutation.isPending}>
            {mutation.isPending ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : null}
            {isEdit ? tCommon("save") : tCommon("create")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
