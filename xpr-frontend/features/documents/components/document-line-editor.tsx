"use client";

import { Plus, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import {
  useFieldArray,
  useWatch,
  type Control,
  type UseFormRegister,
  type UseFormSetValue,
} from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import type { Product, TaxRate } from "@/features/catalog/schemas/product";
import type { DocumentFormValues } from "@/features/documents/schemas/document";
import { computeLine } from "@/features/documents/utils/totals";
import { formatMoney } from "@/lib/format";

/** Ligne vierge : quantité 1, aucune remise, taux par défaut de la société. */
export function emptyLine(defaultTaxRateId: string) {
  return {
    productId: "",
    label: "",
    description: "",
    quantity: 1,
    unit: "",
    unitPrice: 0,
    discountPercent: 0,
    taxRateId: defaultTaxRateId,
  };
}

/**
 * Éditeur des lignes d'un document.
 *
 * Choisir un article du catalogue RECOPIE ses valeurs dans la ligne au lieu de
 * s'y référer : c'est la règle métier du §3 (le libellé, le prix et le taux
 * sont figés au moment de la saisie). Une hausse de tarif au catalogue demain
 * ne doit pas modifier un devis envoyé hier. L'utilisateur reste libre de
 * corriger chaque champ après la recopie.
 *
 * Les montants affichés ici sont un APERÇU calculé en entiers, réplique du
 * calculateur serveur (cf. utils/totals.ts) ; la réponse de l'API fait foi.
 */
export function DocumentLineEditor({
  control,
  register,
  setValue,
  products,
  taxRates,
  defaultTaxRateId,
  currency,
}: {
  control: Control<DocumentFormValues>;
  register: UseFormRegister<DocumentFormValues>;
  setValue: UseFormSetValue<DocumentFormValues>;
  products: readonly Product[];
  taxRates: readonly TaxRate[];
  defaultTaxRateId: string;
  currency: string;
}) {
  const t = useTranslations("documents");
  const locale = useLocale();

  const { fields, append, remove } = useFieldArray({ control, name: "items" });
  // `useWatch` et non `watch()` : il ne réabonne que ce composant, alors que
  // `watch()` rerendrait tout le formulaire à chaque frappe dans une cellule.
  const items = useWatch({ control, name: "items" }) ?? [];

  const rateOf = (taxRateId: string): number =>
    Number(taxRates.find((rate) => rate.id === taxRateId)?.rate ?? 0);

  /** Recopie de l'article choisi. "" = ligne libre, on ne touche à rien. */
  const applyProduct = (index: number, productId: string) => {
    setValue(`items.${index}.productId`, productId);

    const product = products.find((candidate) => candidate.id === productId);

    if (!product) return;

    setValue(`items.${index}.label`, product.name);
    setValue(`items.${index}.unitPrice`, product.unitPriceCents / 100);
    setValue(`items.${index}.unit`, product.unit ?? "");
    setValue(`items.${index}.taxRateId`, product.taxRateId ?? defaultTaxRateId);
    // Remise habituelle de la fiche, recopiée comme le prix et le taux. Le
    // serveur applique la même règle si la ligne ne transmet pas de remise
    // (DocumentItemBuilder) ; la poser ici la rend VISIBLE et corrigeable
    // avant l'envoi, plutôt que de la voir apparaître dans la réponse.
    setValue(
      `items.${index}.discountPercent`,
      Number(product.defaultDiscountPercent),
    );

    if (product.description) {
      setValue(`items.${index}.description`, product.description);
    }
  };

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-medium">{t("form.lines")}</h3>
        <Button
          type="button"
          size="sm"
          variant="outline"
          onClick={() => append(emptyLine(defaultTaxRateId))}
        >
          <Plus aria-hidden />
          {t("form.addLine")}
        </Button>
      </div>

      {fields.length === 0 ? (
        <p className="text-muted-foreground ring-border rounded-lg px-3 py-6 text-center text-sm ring-1">
          {t("form.noLines")}
        </p>
      ) : (
        <div className="ring-border overflow-hidden rounded-lg ring-1">
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-sm">
              <thead>
                <tr className="border-border bg-muted/40 text-muted-foreground border-b text-xs uppercase">
                  <th scope="col" className="w-8" />
                  <th scope="col" className="min-w-56 px-2 py-2 text-start font-medium">
                    {t("form.line.label")}
                  </th>
                  <th scope="col" className="w-24 px-2 py-2 text-end font-medium">
                    {t("form.line.quantity")}
                  </th>
                  <th scope="col" className="w-32 px-2 py-2 text-end font-medium">
                    {t("form.line.unitPrice")}
                  </th>
                  <th scope="col" className="w-20 px-2 py-2 text-end font-medium">
                    {t("form.line.discount")}
                  </th>
                  <th scope="col" className="w-32 px-2 py-2 text-start font-medium">
                    {t("form.line.tax")}
                  </th>
                  <th scope="col" className="w-32 px-2 py-2 text-end font-medium">
                    {t("form.line.total")}
                  </th>
                  <th scope="col" className="w-10" />
                </tr>
              </thead>

              <tbody>
                {fields.map((field, index) => {
                  const item = items[index];
                  const amounts = computeLine({
                    quantity: Number(item?.quantity ?? 0),
                    unitPriceCents: Math.round(Number(item?.unitPrice ?? 0) * 100),
                    discountPercent: Number(item?.discountPercent ?? 0),
                    taxRatePercent: rateOf(item?.taxRateId ?? ""),
                  });

                  return (
                    <tr key={field.id} className="border-border/60 border-b last:border-0">
                      {/* Rang de la ligne : c'est l'ordre qui sera imprimé
                          sur le document, et il rend les erreurs de saisie
                          désignables (« ligne 3 »). */}
                      <td className="text-muted-foreground px-1 text-center text-xs tabular">
                        {index + 1}
                      </td>

                      <td className="px-2 py-1.5">
                        <div className="flex flex-col gap-1">
                          <Select
                            value={item?.productId || "free"}
                            onValueChange={(value) =>
                              applyProduct(index, value === "free" ? "" : value)
                            }
                          >
                            <SelectTrigger
                              size="sm"
                              className="w-full"
                              aria-label={t("form.line.product")}
                            >
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="free">
                                {t("form.line.freeLine")}
                              </SelectItem>
                              {products.map((product) => (
                                <SelectItem key={product.id} value={product.id}>
                                  {product.name}
                                </SelectItem>
                              ))}
                            </SelectContent>
                          </Select>

                          <Input
                            aria-label={t("form.line.label")}
                            placeholder={t("form.line.labelPlaceholder")}
                            className="h-8"
                            {...register(`items.${index}.label`)}
                          />
                        </div>
                      </td>

                      <td className="px-2 py-1.5">
                        <Input
                          type="number"
                          step="0.001"
                          min={0}
                          aria-label={t("form.line.quantity")}
                          className="h-8 text-end tabular"
                          {...register(`items.${index}.quantity`, {
                            valueAsNumber: true,
                          })}
                        />
                      </td>

                      <td className="px-2 py-1.5">
                        <Input
                          type="number"
                          step="0.01"
                          min={0}
                          aria-label={t("form.line.unitPrice")}
                          className="h-8 text-end tabular"
                          {...register(`items.${index}.unitPrice`, {
                            valueAsNumber: true,
                          })}
                        />
                      </td>

                      <td className="px-2 py-1.5">
                        <Input
                          type="number"
                          step="0.01"
                          min={0}
                          max={100}
                          aria-label={t("form.line.discount")}
                          className="h-8 text-end tabular"
                          {...register(`items.${index}.discountPercent`, {
                            valueAsNumber: true,
                          })}
                        />
                      </td>

                      <td className="px-2 py-1.5">
                        <Select
                          value={item?.taxRateId || "none"}
                          onValueChange={(value) =>
                            setValue(
                              `items.${index}.taxRateId`,
                              value === "none" ? "" : value,
                            )
                          }
                        >
                          <SelectTrigger
                            size="sm"
                            className="w-full"
                            aria-label={t("form.line.tax")}
                          >
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="none">
                              {t("form.line.noTax")}
                            </SelectItem>
                            {taxRates.map((rate) => (
                              <SelectItem key={rate.id} value={rate.id}>
                                {rate.labelFr}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </td>

                      <td className="px-2 py-1.5 text-end">
                        <span className="tabular font-medium">
                          {formatMoney(amounts.totalCents, locale, currency)}
                        </span>
                      </td>

                      <td className="px-1 py-1.5 text-center">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon-sm"
                          aria-label={t("form.line.remove")}
                          onClick={() => remove(index)}
                        >
                          <Trash2 aria-hidden className="text-destructive" />
                        </Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
