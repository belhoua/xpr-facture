"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
  catalogKeys,
  createProduct,
  fetchCategories,
  fetchTaxRates,
  updateProduct,
} from "@/features/catalog/api/catalog";
import {
  PRODUCT_TYPES,
  productFormSchema,
  type Product,
  type ProductFormValues,
} from "@/features/catalog/schemas/product";

/**
 * Champs mappables depuis une erreur de validation serveur (RFC 9457).
 *
 * Les PRIX en sont absents volontairement : le serveur les nomme
 * `unitPriceCents` / `costPriceCents` alors que le formulaire saisit des MAD
 * sous `unitPrice` / `costPrice`. Rattacher l'un à l'autre demanderait une
 * table de correspondance qui mentirait sur l'unité ; Zod borne déjà ces deux
 * champs côté client, et un refus serveur s'affiche en bandeau.
 */
const SERVER_FIELDS = [
  "type",
  "name",
  "reference",
  "description",
  "unit",
  "categoryId",
  "taxRateId",
  "trackStock",
  "isActive",
] as const;

const REFERENCE_STALE_TIME = 60 * 60 * 1000;

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
    trackStock: false,
    isActive: true,
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromProduct(product: Product): ProductFormValues {
  return {
    type: product.type,
    name: product.name,
    reference: product.reference ?? "",
    description: product.description ?? "",
    unit: product.unit ?? "",
    categoryId: product.categoryId ?? "",
    taxRateId: product.taxRateId ?? "",
    // Centimes → unités majeures : conversion d'AFFICHAGE uniquement (§7).
    unitPrice: product.unitPriceCents / 100,
    costPrice: (product.costPriceCents ?? 0) / 100,
    trackStock: product.trackStock,
    isActive: product.isActive,
  };
}

/**
 * Création / édition d'un article du catalogue.
 *
 * Le type par défaut est SERVICE : c'est le cas dominant chez les TPE et les
 * indépendants marocains, et c'est le seul qui n'entraîne aucune question de
 * stock. Basculer sur « bien » ouvre le suivi de stock, qu'une contrainte en
 * base interdit sur un service.
 */
export function ProductFormDialog({
  open,
  onOpenChange,
  product,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  product?: Product | null;
}) {
  const t = useTranslations("catalog.products");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(product);

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
        product ? valuesFromProduct(product) : emptyValues(defaultTaxRateId),
      );
    }
  }, [open, product, defaultTaxRateId, form]);

  const type = useWatch({ control: form.control, name: "type" });

  const mutation = useMutation({
    mutationFn: (values: ProductFormValues) =>
      isEdit && product
        ? updateProduct(product.id, values)
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
          id="product-form"
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
                <FieldLabel htmlFor="product-type">{t("form.type")}</FieldLabel>
                <Controller
                  control={form.control}
                  name="type"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="product-type" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {PRODUCT_TYPES.map((value) => (
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
                <FieldLabel htmlFor="product-reference">
                  {t("form.reference")}
                </FieldLabel>
                <Input
                  id="product-reference"
                  placeholder={t("form.referencePlaceholder")}
                  aria-invalid={Boolean(errors.reference)}
                  {...form.register("reference")}
                />
                <FieldError>{fieldError(errors.reference?.message)}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="product-name">{t("form.name")}</FieldLabel>
              <Input
                id="product-name"
                placeholder={t("form.namePlaceholder")}
                aria-invalid={Boolean(errors.name)}
                {...form.register("name")}
              />
              <FieldError>{fieldError(errors.name?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="product-description">
                {t("form.descriptionLabel")}
              </FieldLabel>
              <Textarea
                id="product-description"
                rows={2}
                {...form.register("description")}
              />
              <FieldError>{fieldError(errors.description?.message)}</FieldError>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="product-category">
                  {t("form.category")}
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
                      <SelectTrigger id="product-category" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="none">{t("form.noCategory")}</SelectItem>
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
                <FieldLabel htmlFor="product-tax">{t("form.taxRate")}</FieldLabel>
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
                      <SelectTrigger id="product-tax" className="w-full">
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
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <Field>
                <FieldLabel htmlFor="product-price">
                  {t("form.unitPrice")}
                </FieldLabel>
                <Input
                  id="product-price"
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
                <FieldLabel htmlFor="product-cost">
                  {t("form.costPrice")}
                </FieldLabel>
                <Input
                  id="product-cost"
                  type="number"
                  step="0.01"
                  min={0}
                  className="tabular"
                  aria-invalid={Boolean(errors.costPrice)}
                  {...form.register("costPrice", { valueAsNumber: true })}
                />
                <FieldError>{fieldError(errors.costPrice?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="product-unit">{t("form.unit")}</FieldLabel>
                <Input
                  id="product-unit"
                  placeholder={t("form.unitPlaceholder")}
                  {...form.register("unit")}
                />
                <FieldError>{fieldError(errors.unit?.message)}</FieldError>
              </Field>
            </div>

            {/* Le suivi de stock n'existe que pour un BIEN : la contrainte
                `products_stock_goods_only_check` le refuse sur un service. */}
            {type === "good" && (
              <Field orientation="horizontal">
                <input
                  id="product-track-stock"
                  type="checkbox"
                  className="accent-primary size-4"
                  {...form.register("trackStock")}
                />
                <FieldLabel htmlFor="product-track-stock">
                  {t("form.trackStock")}
                </FieldLabel>
              </Field>
            )}

            <Field orientation="horizontal">
              <input
                id="product-active"
                type="checkbox"
                className="accent-primary size-4"
                {...form.register("isActive")}
              />
              <FieldLabel htmlFor="product-active">
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
          <Button type="submit" form="product-form" disabled={mutation.isPending}>
            {mutation.isPending ? t("form.saving") : t("form.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
