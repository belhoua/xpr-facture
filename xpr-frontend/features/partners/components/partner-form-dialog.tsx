"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQueryClient } from "@tanstack/react-query";
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
import {
  Field,
  FieldError,
  FieldGroup,
  FieldLabel,
} from "@/components/ui/field";
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
  createPartner,
  partnerKeys,
  updatePartner,
} from "@/features/partners/api/partners";
import {
  PARTNER_TYPES,
  partnerFormSchema,
  type Partner,
  type PartnerFormValues,
  type PartnerType,
} from "@/features/partners/schemas/partner";

/**
 * Types proposables à la saisie — les quatre du contrat, `intermediary`
 * compris. Dérivés de l'énumération : une liste recopiée finit par diverger, et
 * on ne s'en aperçoit qu'au type suivant.
 */
const TYPES: readonly PartnerType[] = PARTNER_TYPES;

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = [
  "type",
  "legalName",
  "tradeName",
  "ice",
  "ifNumber",
  "contactName",
  "email",
  "phone",
  "city",
  "address",
  "paymentTermsDays",
  "notes",
] as const;

function emptyValues(): PartnerFormValues {
  return {
    type: "client",
    legalName: "",
    tradeName: "",
    ice: "",
    ifNumber: "",
    contactName: "",
    email: "",
    phone: "",
    city: "",
    address: "",
    // 30 jours : usage dominant au Maroc, modifiable fiche par fiche.
    paymentTermsDays: 30,
    notes: "",
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromPartner(partner: Partner): PartnerFormValues {
  return {
    type: partner.type,
    legalName: partner.legalName,
    tradeName: partner.tradeName ?? "",
    ice: partner.ice ?? "",
    ifNumber: partner.ifNumber ?? "",
    contactName: partner.contactName ?? "",
    email: partner.email ?? "",
    phone: partner.phone ?? "",
    city: partner.city ?? "",
    address: partner.address ?? "",
    paymentTermsDays: partner.paymentTermsDays,
    notes: partner.notes ?? "",
  };
}

/**
 * Création / édition d'un tiers. Un seul composant pour les deux modes.
 *
 * L'ICE n'est pas obligatoire : un particulier n'en a pas, et une fiche doit
 * pouvoir exister avant qu'on ait récupéré la pièce. Quand il est saisi, le
 * serveur vérifie qu'il est unique dans la société — l'erreur revient alors
 * rattachée au champ via applyProblemToForm.
 */
export function PartnerFormDialog({
  open,
  onOpenChange,
  partner,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  partner?: Partner | null;
}) {
  const t = useTranslations("partners");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(partner);

  const form = useForm<PartnerFormValues>({
    resolver: zodResolver(partnerFormSchema),
    defaultValues: emptyValues(),
  });

  useEffect(() => {
    if (open) {
      form.reset(partner ? valuesFromPartner(partner) : emptyValues());
    }
  }, [open, partner, form]);

  const mutation = useMutation({
    mutationFn: (values: PartnerFormValues) =>
      isEdit && partner
        ? updatePartner(partner.id, values)
        : createPartner(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: partnerKeys.all });
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
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("form.editTitle") : t("form.createTitle")}
          </DialogTitle>
          <DialogDescription>
            {isEdit ? t("form.editDescription") : t("form.createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form
          id="partner-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          className="max-h-[60vh] overflow-y-auto pe-1"
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="partner-type">{t("form.type")}</FieldLabel>
                <Controller
                  control={form.control}
                  name="type"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="partner-type">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {TYPES.map((value) => (
                          <SelectItem key={value} value={value}>
                            {t(`types.${value}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.type?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="partner-terms">
                  {t("form.paymentTerms")}
                </FieldLabel>
                <Input
                  id="partner-terms"
                  type="number"
                  min={0}
                  max={365}
                  aria-invalid={Boolean(errors.paymentTermsDays)}
                  {...form.register("paymentTermsDays")}
                />
                <FieldError>
                  {fieldError(errors.paymentTermsDays?.message)}
                </FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="partner-legal-name">
                {t("form.legalName")}
              </FieldLabel>
              <Input
                id="partner-legal-name"
                placeholder={t("form.legalNamePlaceholder")}
                aria-invalid={Boolean(errors.legalName)}
                {...form.register("legalName")}
              />
              <FieldError>{fieldError(errors.legalName?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="partner-trade-name">
                {t("form.tradeName")}
              </FieldLabel>
              <Input
                id="partner-trade-name"
                placeholder={t("form.tradeNamePlaceholder")}
                {...form.register("tradeName")}
              />
              <FieldError>{fieldError(errors.tradeName?.message)}</FieldError>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="partner-ice">{t("form.ice")}</FieldLabel>
                <Input
                  id="partner-ice"
                  inputMode="numeric"
                  placeholder="001234567890123"
                  aria-invalid={Boolean(errors.ice)}
                  {...form.register("ice")}
                />
                <FieldError>{fieldError(errors.ice?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="partner-if">
                  {t("form.ifNumber")}
                </FieldLabel>
                <Input id="partner-if" {...form.register("ifNumber")} />
                <FieldError>{fieldError(errors.ifNumber?.message)}</FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="partner-contact">
                  {t("form.contactName")}
                </FieldLabel>
                <Input id="partner-contact" {...form.register("contactName")} />
                <FieldError>
                  {fieldError(errors.contactName?.message)}
                </FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="partner-email">
                  {t("form.email")}
                </FieldLabel>
                <Input
                  id="partner-email"
                  type="email"
                  aria-invalid={Boolean(errors.email)}
                  {...form.register("email")}
                />
                <FieldError>{fieldError(errors.email?.message)}</FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="partner-phone">
                  {t("form.phone")}
                </FieldLabel>
                <Input id="partner-phone" {...form.register("phone")} />
                <FieldError>{fieldError(errors.phone?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="partner-city">{t("form.city")}</FieldLabel>
                <Input id="partner-city" {...form.register("city")} />
                <FieldError>{fieldError(errors.city?.message)}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="partner-address">
                {t("form.address")}
              </FieldLabel>
              <Textarea
                id="partner-address"
                rows={2}
                {...form.register("address")}
              />
              <FieldError>{fieldError(errors.address?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="partner-notes">{t("form.notes")}</FieldLabel>
              <Textarea
                id="partner-notes"
                rows={2}
                {...form.register("notes")}
              />
              <FieldError>{fieldError(errors.notes?.message)}</FieldError>
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t("form.cancel")}
          </Button>
          <Button
            type="submit"
            form="partner-form"
            disabled={mutation.isPending}
          >
            {isEdit ? t("form.save") : t("form.create")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
