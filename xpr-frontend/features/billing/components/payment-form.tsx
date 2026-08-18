"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { Info, Lock } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useForm } from "react-hook-form";
import { z } from "zod";

import {
  CheckoutSteps,
  type CheckoutStep,
} from "@/features/billing/components/checkout-steps";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Separator } from "@/components/ui/separator";
import {
  chargedCents,
  type BillingPeriod,
  type Plan,
} from "@/features/billing/types/plan";
import { formatMoney } from "@/lib/format";
import { Link, useRouter } from "@/lib/i18n/navigation";

/**
 * Étape de paiement — SIMULÉE, et affichée comme telle.
 *
 * ⚠️ Ce composant ne transmet AUCUNE donnée de carte : les valeurs saisies
 * restent dans l'état local de React Hook Form et sont jetées au démontage.
 * Encaisser réellement exigera de rediriger vers la page hébergée du CMI
 * (Phase 3) — une application qui manipule elle-même un PAN tombe sous PCI-DSS,
 * ce qu'on ne veut à aucun prix. Les champs ci-dessous sont une maquette
 * d'ergonomie, pas un formulaire de paiement fonctionnel.
 */
const paymentSchema = z.object({
  cardHolder: z.string().min(2, "validation.required"),
  cardNumber: z
    .string()
    .transform((value) => value.replace(/\s/g, ""))
    .pipe(z.string().regex(/^\d{16}$/, "validation.required")),
  expiry: z.string().regex(/^(0[1-9]|1[0-2])\/\d{2}$/, "validation.required"),
  cvc: z.string().regex(/^\d{3,4}$/, "validation.required"),
});

type PaymentValues = z.input<typeof paymentSchema>;

export function PaymentForm({
  plan,
  period,
}: {
  plan: Plan;
  period: BillingPeriod;
}) {
  const t = useTranslations("pricing");
  const tRoot = useTranslations();
  const locale = useLocale();
  const router = useRouter();

  const form = useForm<PaymentValues>({
    resolver: zodResolver(paymentSchema),
    defaultValues: { cardHolder: "", cardNumber: "", expiry: "", cvc: "" },
  });

  const total = chargedCents(plan, period);
  const planName = t(`plans.${plan.id}.name`);
  const errors = form.formState.errors;

  const stepLabels: Record<CheckoutStep, string> = {
    account: t("steps.account"),
    plan: t("steps.plan"),
    payment: t("steps.payment"),
    done: t("steps.done"),
  };

  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  async function onSubmit(): Promise<void> {
    // Latence volontaire : sans elle, l'état « traitement en cours » clignote
    // et l'utilisateur doute que quelque chose se soit passé.
    await new Promise((resolve) => setTimeout(resolve, 900));
    router.push(`/subscribe/success?plan=${plan.id}`);
  }

  return (
    <div className="mx-auto max-w-4xl">
      <CheckoutSteps current="payment" labels={stepLabels} />

      <div className="mt-10 grid gap-8 md:grid-cols-[1fr_20rem]">
        <div>
          <h1 className="font-heading text-2xl font-semibold tracking-tight">
            {t("payment.title")}
          </h1>
          <p className="text-muted-foreground mt-1 text-sm">
            {t("payment.description", { plan: planName })}
          </p>

          <Alert className="mt-5">
            <Info aria-hidden />
            <AlertTitle>{t("payment.simulated")}</AlertTitle>
            <AlertDescription>{t("payment.simulatedHint")}</AlertDescription>
          </Alert>

          <form onSubmit={form.handleSubmit(onSubmit)} className="mt-6">
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="cardHolder">
                  {t("payment.cardHolder")}
                </FieldLabel>
                <Input
                  id="cardHolder"
                  autoComplete="off"
                  aria-invalid={Boolean(errors.cardHolder)}
                  {...form.register("cardHolder")}
                />
                <FieldError>{fieldError(errors.cardHolder?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="cardNumber">
                  {t("payment.cardNumber")}
                </FieldLabel>
                <Input
                  id="cardNumber"
                  inputMode="numeric"
                  autoComplete="off"
                  placeholder="4242 4242 4242 4242"
                  className="tabular"
                  aria-invalid={Boolean(errors.cardNumber)}
                  {...form.register("cardNumber")}
                />
                <FieldError>{fieldError(errors.cardNumber?.message)}</FieldError>
              </Field>

              <div className="grid grid-cols-2 gap-4">
                <Field>
                  <FieldLabel htmlFor="expiry">{t("payment.expiry")}</FieldLabel>
                  <Input
                    id="expiry"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="12/28"
                    className="tabular"
                    aria-invalid={Boolean(errors.expiry)}
                    {...form.register("expiry")}
                  />
                  <FieldError>{fieldError(errors.expiry?.message)}</FieldError>
                </Field>

                <Field>
                  <FieldLabel htmlFor="cvc">{t("payment.cvc")}</FieldLabel>
                  <Input
                    id="cvc"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="123"
                    className="tabular"
                    aria-invalid={Boolean(errors.cvc)}
                    {...form.register("cvc")}
                  />
                  <FieldError>{fieldError(errors.cvc?.message)}</FieldError>
                </Field>
              </div>
            </FieldGroup>

            <Button
              type="submit"
              size="lg"
              className="mt-6 w-full"
              loading={form.formState.isSubmitting}
            >
              <Lock aria-hidden />
              {form.formState.isSubmitting
                ? t("payment.submitting")
                : t("payment.submit", { amount: formatMoney(total, locale) })}
            </Button>

            <Button asChild variant="ghost" size="sm" className="mt-2 w-full">
              <Link href="/subscribe">{t("payment.back")}</Link>
            </Button>
          </form>
        </div>

        <aside className="bg-card ring-border h-fit rounded-xl p-5 ring-1">
          <p className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {t("steps.plan")}
          </p>
          <p className="font-heading mt-2 text-base font-semibold">{planName}</p>
          <p className="text-muted-foreground mt-0.5 text-sm">
            {t(period === "yearly" ? "yearly" : "monthly")}
          </p>

          <Separator className="my-4" />

          <div className="flex items-baseline justify-between">
            <span className="text-muted-foreground text-sm">
              {t("payment.total")}
            </span>
            <span className="amount text-lg font-semibold">
              {formatMoney(total, locale)}
            </span>
          </div>

          <p className="text-muted-foreground mt-3 text-xs">{t("vatNote")}</p>
        </aside>
      </div>
    </div>
  );
}
