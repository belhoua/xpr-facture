"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useLocale, useTranslations } from "next-intl";
import { Controller, useForm } from "react-hook-form";

import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Link, useRouter } from "@/lib/i18n/navigation";

import { applyProblemToForm, useRegister } from "../hooks/use-auth";
import {
  LEGAL_FORMS,
  registerSchema,
  type RegisterValues,
} from "../schemas/auth";

/**
 * US-1 : inscription compte + société en une étape (6 champs).
 * Le formulaire S'ADAPTE à la forme juridique : « auto-entrepreneur »
 * annonce l'activation automatique de la mention TVA non applicable
 * (le backend pose le drapeau vat_exempt, testé côté Pest).
 */
export function RegisterForm() {
  const t = useTranslations("auth");
  const tRoot = useTranslations();
  const locale = useLocale();
  const router = useRouter();
  const registration = useRegister();

  const form = useForm<RegisterValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: "",
      email: "",
      password: "",
      company_legal_name: "",
      locale: locale as RegisterValues["locale"],
    },
  });

  const legalForm = form.watch("company_legal_form");
  const errors = form.formState.errors;

  // Clé Zod (ex. "validation.required") → texte traduit ; message serveur → tel quel
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  async function onSubmit(values: RegisterValues): Promise<void> {
    try {
      await registration.mutateAsync({
        ...values,
        // La langue au moment de la soumission (le switch FR/AR peut avoir changé)
        locale: locale as RegisterValues["locale"],
      });
      router.push("/dashboard");
    } catch (error) {
      applyProblemToForm(error, form.setError, [
        "name",
        "email",
        "password",
        "company_legal_name",
        "company_legal_form",
      ]);
    }
  }

  return (
    <Card className="w-full max-w-md">
      <CardHeader>
        <CardTitle>{t("register.title")}</CardTitle>
        <CardDescription>{t("register.description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
          <FieldGroup>
            {errors.root?.server && (
              <Alert variant="destructive">
                <AlertDescription>{errors.root.server.message}</AlertDescription>
              </Alert>
            )}

            <Field data-invalid={errors.name !== undefined}>
              <FieldLabel htmlFor="name">{t("register.name")}</FieldLabel>
              <Input id="name" autoComplete="name" {...form.register("name")} />
              {errors.name && (
                <FieldError>{fieldError(errors.name.message)}</FieldError>
              )}
            </Field>

            <Field data-invalid={errors.email !== undefined}>
              <FieldLabel htmlFor="email">{t("common.email")}</FieldLabel>
              <Input
                id="email"
                type="email"
                dir="ltr"
                autoComplete="email"
                {...form.register("email")}
              />
              {errors.email && (
                <FieldError>{fieldError(errors.email.message)}</FieldError>
              )}
            </Field>

            <Field data-invalid={errors.password !== undefined}>
              <FieldLabel htmlFor="password">{t("common.password")}</FieldLabel>
              <Input
                id="password"
                type="password"
                autoComplete="new-password"
                {...form.register("password")}
              />
              {errors.password && (
                <FieldError>{fieldError(errors.password.message)}</FieldError>
              )}
            </Field>

            <Field data-invalid={errors.company_legal_name !== undefined}>
              <FieldLabel htmlFor="company_legal_name">
                {t("register.companyName")}
              </FieldLabel>
              <Input
                id="company_legal_name"
                autoComplete="organization"
                {...form.register("company_legal_name")}
              />
              {errors.company_legal_name && (
                <FieldError>
                  {fieldError(errors.company_legal_name.message)}
                </FieldError>
              )}
            </Field>

            <Field data-invalid={errors.company_legal_form !== undefined}>
              <FieldLabel htmlFor="company_legal_form">
                {t("register.legalForm")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="company_legal_form"
                render={({ field }) => (
                  <Select value={field.value ?? ""} onValueChange={field.onChange}>
                    <SelectTrigger id="company_legal_form" className="w-full">
                      <SelectValue
                        placeholder={t("register.legalFormPlaceholder")}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {LEGAL_FORMS.map((value) => (
                        <SelectItem key={value} value={value}>
                          {t(`legalForms.${value}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {errors.company_legal_form && (
                <FieldError>
                  {fieldError(errors.company_legal_form.message)}
                </FieldError>
              )}
            </Field>

            {legalForm === "auto_entrepreneur" && (
              <Alert>
                <AlertTitle>{t("register.autoEntrepreneurTitle")}</AlertTitle>
                <AlertDescription>
                  {t("register.autoEntrepreneurHint")}
                </AlertDescription>
              </Alert>
            )}

            <Button type="submit" disabled={registration.isPending}>
              {registration.isPending
                ? t("common.submitting")
                : t("register.submit")}
            </Button>

            <p className="text-muted-foreground text-center text-sm">
              {t("register.hasAccount")}{" "}
              <Link href="/login" className="text-foreground underline">
                {t("login.title")}
              </Link>
            </p>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  );
}
