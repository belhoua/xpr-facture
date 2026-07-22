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
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import {
  catalogKeys,
  createCategory,
  updateCategory,
} from "@/features/catalog/api/catalog";
import {
  CATEGORY_COLORS,
  categoryFormSchema,
  type Category,
  type CategoryFormValues,
} from "@/features/catalog/schemas/category";
import { cn } from "@/lib/utils";

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = ["name", "description", "color", "isActive"] as const;

function emptyValues(): CategoryFormValues {
  return {
    name: "",
    description: "",
    color: CATEGORY_COLORS[0],
    isActive: true,
  };
}

function valuesFromCategory(category: Category): CategoryFormValues {
  return {
    name: category.name,
    description: category.description ?? "",
    color: category.color ?? "",
    isActive: category.isActive,
  };
}

/**
 * Création / édition d'une catégorie. Un seul composant pour les deux modes.
 *
 * L'unicité du nom est vérifiée par le serveur, INSENSIBLE À LA CASSE (index
 * sur `lower(name)`) : « Impression » et « impression » sont la même catégorie.
 * L'erreur revient rattachée au champ via `applyProblemToForm`.
 */
export function CategoryFormDialog({
  open,
  onOpenChange,
  category,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  category?: Category | null;
}) {
  const t = useTranslations("catalog.categories");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();
  const isEdit = Boolean(category);

  const form = useForm<CategoryFormValues>({
    resolver: zodResolver(categoryFormSchema),
    defaultValues: emptyValues(),
  });

  useEffect(() => {
    if (open) {
      form.reset(category ? valuesFromCategory(category) : emptyValues());
    }
  }, [open, category, form]);

  const mutation = useMutation({
    mutationFn: (values: CategoryFormValues) =>
      isEdit && category
        ? updateCategory(category.id, values)
        : createCategory(values),
    onSuccess: async () => {
      // Les articles portent le nom et la couleur de leur catégorie : les
      // deux listes se rafraîchissent, pas seulement celle des catégories.
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
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("form.editTitle") : t("form.createTitle")}
          </DialogTitle>
          <DialogDescription>{t("form.description")}</DialogDescription>
        </DialogHeader>

        <form
          id="category-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            <Field>
              <FieldLabel htmlFor="category-name">{t("form.name")}</FieldLabel>
              <Input
                id="category-name"
                placeholder={t("form.namePlaceholder")}
                aria-invalid={Boolean(errors.name)}
                {...form.register("name")}
              />
              <FieldError>{fieldError(errors.name?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="category-description">
                {t("form.descriptionLabel")}
              </FieldLabel>
              <Textarea
                id="category-description"
                rows={2}
                {...form.register("description")}
              />
              <FieldError>{fieldError(errors.description?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="category-color">{t("form.color")}</FieldLabel>
              <Controller
                control={form.control}
                name="color"
                render={({ field }) => (
                  <div
                    id="category-color"
                    role="radiogroup"
                    aria-label={t("form.color")}
                    className="flex flex-wrap gap-1.5"
                  >
                    {CATEGORY_COLORS.map((color) => (
                      <button
                        key={color}
                        type="button"
                        role="radio"
                        aria-checked={field.value === color}
                        aria-label={color}
                        onClick={() => field.onChange(color)}
                        style={{ backgroundColor: color }}
                        className={cn(
                          "focus-visible:outline-ring size-7 rounded-md ring-offset-2 ring-offset-background transition-[box-shadow] focus-visible:outline-2",
                          field.value === color && "ring-foreground ring-2",
                        )}
                      />
                    ))}
                  </div>
                )}
              />
              <FieldError>{fieldError(errors.color?.message)}</FieldError>
            </Field>

            <Field orientation="horizontal">
              <input
                id="category-active"
                type="checkbox"
                className="accent-primary size-4"
                {...form.register("isActive")}
              />
              <FieldLabel htmlFor="category-active">
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
          <Button type="submit" form="category-form" disabled={mutation.isPending}>
            {mutation.isPending ? t("form.saving") : t("form.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
