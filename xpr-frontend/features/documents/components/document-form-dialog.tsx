"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo } from "react";
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
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
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
import { catalogKeys, fetchProducts, fetchTaxRates } from "@/features/catalog/api/catalog";
import {
  createDocument,
  documentKeys,
  updateDocument,
} from "@/features/documents/api/documents";
import {
  DocumentLineEditor,
  emptyLine,
} from "@/features/documents/components/document-line-editor";
import {
  documentFormSchema,
  type Document,
  type DocumentFormValues,
  type DocumentType,
} from "@/features/documents/schemas/document";
import { computeTotals } from "@/features/documents/utils/totals";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import { DEFAULT_CURRENCY, formatMoney } from "@/lib/format";

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = [
  "partnerId",
  "clientName",
  "issuedAt",
  "dueAt",
  "notes",
  "terms",
] as const;

/** Une heure : le référentiel de TVA et le catalogue ne bougent pas en séance. */
const REFERENCE_STALE_TIME = 60 * 60 * 1000;

function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Un document neuf s'ouvre avec UNE ligne prête à remplir : facturer en moins
 * de 60 secondes (§2) suppose de ne pas commencer par cliquer « ajouter ».
 */
function emptyValues(defaultTaxRateId: string): DocumentFormValues {
  return {
    partnerId: "",
    clientName: "",
    issuedAt: todayIso(),
    dueAt: "",
    notes: "",
    terms: "",
    items: [emptyLine(defaultTaxRateId)],
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromDocument(document: Document): DocumentFormValues {
  return {
    partnerId: document.partnerId ?? "",
    clientName: document.clientName,
    issuedAt: document.issuedAt ?? "",
    dueAt: document.dueAt ?? "",
    notes: document.notes ?? "",
    terms: document.terms ?? "",
    items: (document.items ?? []).map((item) => ({
      productId: item.productId ?? "",
      label: item.label,
      description: item.description ?? "",
      quantity: Number(item.quantity),
      unit: item.unit ?? "",
      // Centimes → unités majeures : conversion d'AFFICHAGE, refaite en sens
      // inverse à l'envoi. Aucun calcul métier ne passe par ce flottant (§7).
      unitPrice: item.unitPriceCents / 100,
      discountPercent: Number(item.discountPercent),
      taxRateId: item.taxRateId ?? "",
    })),
  };
}

/**
 * Création / édition d'un document commercial (facture, devis, avoir).
 *
 * Un seul composant pour les trois : ils partagent la même structure, seul le
 * `type` change — et il n'est transmis qu'à la CRÉATION, puisque muter un devis
 * en facture contournerait la numérotation.
 *
 * L'édition n'est offerte que sur un BROUILLON (§3) ; c'est la liste qui filtre
 * l'action, et le serveur qui refuse en 409 si l'on force le passage.
 */
export function DocumentFormDialog({
  open,
  onOpenChange,
  type,
  document,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  type: DocumentType;
  document?: Document | null;
}) {
  const t = useTranslations("documents");
  const tRoot = useTranslations();
  const locale = useLocale();
  const queryClient = useQueryClient();
  const isEdit = Boolean(document);

  const form = useForm<DocumentFormValues>({
    resolver: zodResolver(documentFormSchema),
    defaultValues: emptyValues(""),
  });

  const partnersQuery = useQuery({
    queryKey: partnerKeys.list({ type: "client", search: "" }),
    queryFn: () => fetchPartners({ type: "client", search: "" }),
    enabled: open,
  });

  const productsQuery = useQuery({
    queryKey: catalogKeys.productList({
      search: "",
      type: "all",
      categoryId: "all",
    }),
    queryFn: () =>
      fetchProducts({ search: "", type: "all", categoryId: "all" }),
    enabled: open,
    staleTime: REFERENCE_STALE_TIME,
  });

  const taxRatesQuery = useQuery({
    queryKey: catalogKeys.taxRates(),
    queryFn: fetchTaxRates,
    enabled: open,
    staleTime: REFERENCE_STALE_TIME,
  });

  const taxRates = useMemo(() => taxRatesQuery.data ?? [], [taxRatesQuery.data]);
  const products = productsQuery.data?.data ?? [];

  /** Taux proposé sur une ligne neuve : celui marqué par défaut au référentiel. */
  const defaultTaxRateId = useMemo(
    () => taxRates.find((rate) => rate.isDefault)?.id ?? "",
    [taxRates],
  );

  // `defaultTaxRateId` fait partie des dépendances : si le référentiel arrive
  // après l'ouverture (cache froid), le formulaire vierge est réinitialisé
  // pour bénéficier du taux par défaut. La liste précharge ce référentiel, si
  // bien qu'en pratique il est déjà là — et le cas résiduel se produit dans la
  // seconde qui suit l'ouverture, avant toute saisie.
  useEffect(() => {
    if (open) {
      form.reset(
        document ? valuesFromDocument(document) : emptyValues(defaultTaxRateId),
      );
    }
  }, [open, document, defaultTaxRateId, form]);

  const partnerId = useWatch({ control: form.control, name: "partnerId" });
  // Pas de `?? []` ici : la valeur de repli créerait un tableau neuf à chaque
  // rendu et invaliderait le mémo ci-dessous en permanence.
  const items = useWatch({ control: form.control, name: "items" });

  /** Aperçu du pied de document, recalculé à chaque frappe (cf. utils/totals). */
  const totals = useMemo(
    () =>
      computeTotals(
        (items ?? []).map((item) => ({
          quantity: Number(item.quantity ?? 0),
          unitPriceCents: Math.round(Number(item.unitPrice ?? 0) * 100),
          discountPercent: Number(item.discountPercent ?? 0),
          taxRatePercent: Number(
            taxRates.find((rate) => rate.id === item.taxRateId)?.rate ?? 0,
          ),
        })),
      ),
    [items, taxRates],
  );

  const mutation = useMutation({
    mutationFn: (values: DocumentFormValues) =>
      isEdit && document
        ? updateDocument(document.id, values)
        : createDocument(type, values),
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: documentKeys.all });
      queryClient.setQueryData(documentKeys.detail(saved.id), saved);
      onOpenChange(false);
    },
    onError: (error) => {
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  const currency = document?.currency ?? DEFAULT_CURRENCY;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-5xl">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t(`form.editTitle.${type}`) : t(`form.createTitle.${type}`)}
          </DialogTitle>
          <DialogDescription>
            {isEdit ? t("form.editDescription") : t("form.createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form
          id="document-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          className="max-h-[65vh] space-y-4 overflow-y-auto pe-1"
        >
          {errors.root?.server && (
            <p className="text-destructive text-sm" role="alert">
              {errors.root.server.message}
            </p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="document-partner">
                {t("form.partner")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="partnerId"
                render={({ field }) => (
                  <Select
                    value={field.value || "walk-in"}
                    onValueChange={(value) =>
                      field.onChange(value === "walk-in" ? "" : value)
                    }
                  >
                    <SelectTrigger id="document-partner" className="w-full">
                      <SelectValue placeholder={t("form.partnerPlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {/* Client de passage : le nom est saisi à la main, la
                          fiche tiers n'est pas obligatoire pour facturer. */}
                      <SelectItem value="walk-in">
                        {t("form.walkInClient")}
                      </SelectItem>
                      {(partnersQuery.data?.data ?? []).map((partner) => (
                        <SelectItem key={partner.id} value={partner.id}>
                          {partner.displayName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{fieldError(errors.partnerId?.message)}</FieldError>
            </Field>

            {/* Masqué dès qu'un tiers est choisi : le serveur recopie alors sa
                raison sociale, et une saisie libre serait ignorée. */}
            {partnerId === "" && (
              <Field className="sm:col-span-2">
                <FieldLabel htmlFor="document-client-name">
                  {t("form.clientName")}
                </FieldLabel>
                <Input
                  id="document-client-name"
                  placeholder={t("form.clientPlaceholder")}
                  aria-invalid={Boolean(errors.clientName)}
                  {...form.register("clientName")}
                />
                <FieldError>{fieldError(errors.clientName?.message)}</FieldError>
              </Field>
            )}

            <Field>
              <FieldLabel htmlFor="document-issued-at">
                {t("form.issuedAt")}
              </FieldLabel>
              <Input
                id="document-issued-at"
                type="date"
                aria-invalid={Boolean(errors.issuedAt)}
                {...form.register("issuedAt")}
              />
              <FieldError>{fieldError(errors.issuedAt?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="document-due-at">
                {t("form.dueAt")}
              </FieldLabel>
              <Input
                id="document-due-at"
                type="date"
                aria-invalid={Boolean(errors.dueAt)}
                {...form.register("dueAt")}
              />
              <FieldError>{fieldError(errors.dueAt?.message)}</FieldError>
            </Field>
          </div>

          <DocumentLineEditor
            control={form.control}
            register={form.register}
            setValue={form.setValue}
            products={products}
            taxRates={taxRates}
            defaultTaxRateId={defaultTaxRateId}
            currency={currency}
          />

          {/* Pied de document : aperçu local, remplacé par les montants du
              serveur dès l'enregistrement. */}
          <div className="flex justify-end">
            <dl className="ring-border w-full max-w-72 space-y-1 rounded-lg px-3 py-2 text-sm ring-1">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">{t("totals.subtotal")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.subtotalCents, locale, currency)}
                </dd>
              </div>
              {totals.discountCents > 0 && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">
                    {t("totals.discount")}
                  </dt>
                  <dd className="tabular">
                    −{formatMoney(totals.discountCents, locale, currency)}
                  </dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt className="text-muted-foreground">{t("totals.tax")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.taxCents, locale, currency)}
                </dd>
              </div>
              <div className="border-border flex justify-between border-t pt-1 font-semibold">
                <dt>{t("totals.total")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.totalCents, locale, currency)}
                </dd>
              </div>
            </dl>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="document-notes">{t("form.notes")}</FieldLabel>
              <Textarea id="document-notes" rows={2} {...form.register("notes")} />
              <FieldError>{fieldError(errors.notes?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="document-terms">{t("form.terms")}</FieldLabel>
              <Textarea id="document-terms" rows={2} {...form.register("terms")} />
              <FieldError>{fieldError(errors.terms?.message)}</FieldError>
            </Field>
          </div>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t("form.cancel")}
          </Button>
          <Button type="submit" form="document-form" disabled={mutation.isPending}>
            {mutation.isPending ? t("form.saving") : t("form.save")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
