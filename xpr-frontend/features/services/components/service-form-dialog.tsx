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
import { Textarea } from "@/components/ui/textarea";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import {
  catalogKeys,
  createProduct,
  fetchCategories,
  fetchTaxRates,
  updateProduct,
} from "@/features/catalog/api/catalog";
import {
  productFormSchema,
  type Product,
  type ProductFormValues,
} from "@/features/catalog/schemas/product";
import { SERVICE_UNITS } from "@/features/services/schemas/service";

/**
 * Champs que le serveur nomme à l'identique et qu'une erreur RFC 9457 peut
 * donc rattacher directement. Prix et remise en sont absents : le serveur les
 * nomme `unitPriceCents` et `defaultDiscountPercent` là où le formulaire saisit
 * des MAD et un pourcentage simple (cf. ProductFormDialog).
 */
const SERVER_FIELDS = [
  "name",
  "reference",
  "description",
  "unit",
  "categoryId",
  "taxRateId",
  "isActive",
] as const;

const REFERENCE_STALE_TIME = 60 * 60 * 1000;

/**
 * Un service ne se stocke pas et n'a pas de prix de revient à l'écran : les
 * deux champs sont neutralisés ici plutôt qu'omis, parce que le payload est
 * partagé avec le catalogue. `trackStock` à `true` sur un service serait de
 * toute façon refusé par `products_stock_goods_only_check`.
 */
function emptyValues(defaultTaxRateId: string): ProductFormValues {
  return {
    type: "service",
    name: "",
    reference: "",
    description: "",
    unit: "",
    categoryId: "",
    taxRateId: defaultTaxRateId,
    unitPrice: 0,
    costPrice: 0,
    defaultDiscount: 0,
    trackStock: false,
    isActive: true,
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromService(service: Product): ProductFormValues {
  return {
    type: "service",
    name: service.name,
    reference: service.reference ?? "",
    description: service.description ?? "",
    unit: service.unit ?? "",
    categoryId: service.categoryId ?? "",
    taxRateId: service.taxRateId ?? "",
    // Centimes → unités majeures : conversion d'AFFICHAGE uniquement (§7).
    unitPrice: service.unitPriceCents / 100,
    costPrice: (service.costPriceCents ?? 0) / 100,
    defaultDiscount: Number(service.defaultDiscountPercent),
    trackStock: false,
    isActive: service.isActive,
  };
}

/**
 * Création / édition d'une prestation.
 *
 * C'est le formulaire du catalogue débarrassé de tout ce qui ne concerne qu'un
 * bien matériel — nature, suivi de stock, prix de revient. Il écrit dans la
 * même table et par la même API : « service » n'est pas une entité distincte,
 * c'est la valeur `type = 'service'` du catalogue.
 */
export function ServiceFormDialog({
  open,
  onOpenChange,
  service,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  service?: Product | null;
}) {
  const t = useTranslations("services");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(service);

  const categoriesQuery = useQuery({
    queryKey: catalogKeys.categoryList(""),
    queryFn: () => fetchCategories(""),
    enabled: open,
  });

  const taxRatesQuery = useQuery({
    queryKey: catalogKeys.taxRates(),
    queryFn: fetchTaxRates,
    enabled: open,
    staleTime: REFERENCE_STALE_TIME,
  });

  const taxRates = taxRatesQuery.data ?? [];
  const defaultTaxRateId = taxRates.find((rate) => rate.isDefault)?.id ?? "";

  const form = useForm<ProductFormValues>({
    resolver: zodResolver(productFormSchema),
    defaultValues: emptyValues(""),
  });

  useEffect(() => {
    if (open) {
      form.reset(
        service ? valuesFromService(service) : emptyValues(defaultTaxRateId),
      );
    }
  }, [open, service, defaultTaxRateId, form]);

  const mutation = useMutation({
    mutationFn: (values: ProductFormValues) =>
      isEdit && service
        ? updateProduct(service.id, values)
        : createProduct(values),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: catalogKeys.all });
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
          <DialogDescription>{t("form.description")}</DialogDescription>
        </DialogHeader>

        <form
          id="service-form"
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
                <FieldLabel htmlFor="service-name">{t("form.name")}</FieldLabel>
                <Input
                  id="service-name"
                  placeholder={t("form.namePlaceholder")}
                  aria-invalid={Boolean(errors.name)}
                  {...form.register("name")}
                />
                <FieldError>{fieldError(errors.name?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="service-reference">
                  {t("form.reference")}
                </FieldLabel>
                <Input
                  id="service-reference"
                  placeholder={t("form.referencePlaceholder")}
                  aria-invalid={Boolean(errors.reference)}
                  {...form.register("reference")}
                />
                <FieldError>{fieldError(errors.reference?.message)}</FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                {/* La « nature » d'une prestation est une CATÉGORIE en base :
                    chaque métier a sa nomenclature, elle se paramètre sans
                    migration (§3). Une société neuve en reçoit quatre. */}
                <FieldLabel htmlFor="service-category">
                  {t("form.kind")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="categoryId"
                  render={({ field }) => (
                    <Select
                      value={field.value || "none"}
                      onValueChange={(value) =>
                        field.onChange(value === "none" ? "" : value)
                      }
                    >
                      <SelectTrigger id="service-category" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">{t("form.noKind")}</SelectItem>
                        {(categoriesQuery.data?.data ?? []).map((category) => (
                          <SelectItem key={category.id} value={category.id}>
                            {category.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.categoryId?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="service-discount">
                  {t("form.discount")}
                </FieldLabel>
                <Input
                  id="service-discount"
                  type="number"
                  step="0.01"
                  min={0}
                  max={100}
                  className="tabular"
                  aria-invalid={Boolean(errors.defaultDiscount)}
                  {...form.register("defaultDiscount", { valueAsNumber: true })}
                />
                <p className="text-muted-foreground text-xs">
                  {t("form.discountHint")}
                </p>
                <FieldError>
                  {fieldError(errors.defaultDiscount?.message)}
                </FieldError>
              </Field>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <Field>
                <FieldLabel htmlFor="service-price">
                  {t("form.unitPrice")}
                </FieldLabel>
                <Input
                  id="service-price"
                  type="number"
                  step="0.01"
                  min={0}
                  className="tabular"
                  aria-invalid={Boolean(errors.unitPrice)}
                  {...form.register("unitPrice", { valueAsNumber: true })}
                />
                <FieldError>{fieldError(errors.unitPrice?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="service-tax">{t("form.taxRate")}</FieldLabel>
                <Controller
                  control={form.control}
                  name="taxRateId"
                  render={({ field }) => (
                    <Select
                      value={field.value || "none"}
                      onValueChange={(value) =>
                        field.onChange(value === "none" ? "" : value)
                      }
                    >
                      <SelectTrigger id="service-tax" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">{t("form.noTaxRate")}</SelectItem>
                        {taxRates.map((rate) => (
                          <SelectItem key={rate.id} value={rate.id}>
                            {rate.labelFr}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                <FieldError>{fieldError(errors.taxRateId?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="service-unit">{t("form.unit")}</FieldLabel>
                {/* Saisie LIBRE assortie de suggestions, et non un <Select>
                    fermé : la colonne est du texte libre et la liste réelle
                    varie par métier (« nuitée », « m² », « intervention »…).
                    Une liste fermée obligerait à migrer pour chaque métier. */}
                <Input
                  id="service-unit"
                  list="service-units"
                  placeholder={t("form.unitPlaceholder")}
                  {...form.register("unit")}
                />
                <datalist id="service-units">
                  {SERVICE_UNITS.map((unit) => (
                    <option key={unit} value={unit} />
                  ))}
                </datalist>
                <FieldError>{fieldError(errors.unit?.message)}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="service-description">
                {t("form.descriptionLabel")}
              </FieldLabel>
              <Textarea
                id="service-description"
                rows={4}
                placeholder={t("form.descriptionPlaceholder")}
                {...form.register("description")}
              />
              <FieldError>{fieldError(errors.description?.message)}</FieldError>
            </Field>

            <Field orientation="horizontal">
              <input
                id="service-active"
                type="checkbox"
                className="accent-primary size-4"
                {...form.register("isActive")}
              />
              <FieldLabel htmlFor="service-active">
                {t("form.isActive")}
              </FieldLabel>
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
          <Button type="submit" form="service-form" loading={mutation.isPending}>
            {mutation.isPending
              ? t("form.saving")
              : isEdit
                ? t("form.submitEdit")
                : t("form.submitCreate")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
