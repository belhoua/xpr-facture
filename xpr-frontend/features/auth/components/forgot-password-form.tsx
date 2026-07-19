"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { useForm } from "react-hook-form";

import { Alert, AlertDescription } from "@/components/ui/alert";
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
import { Link } from "@/lib/i18n/navigation";

import { applyProblemToForm, useForgotPassword } from "../hooks/use-auth";
import {
  forgotPasswordSchema,
  type ForgotPasswordValues,
} from "../schemas/auth";

/**
 * US-5. Le backend répond TOUJOURS 200 avec le même message (anti-
 * énumération) : l'état de succès affiché ici est donc lui aussi neutre.
 */
export function ForgotPasswordForm() {
  const t = useTranslations("auth");
  const tRoot = useTranslations();
  const forgot = useForgotPassword();

  const form = useForm<ForgotPasswordValues>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: "" },
  });

  const errors = form.formState.errors;

  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  async function onSubmit(values: ForgotPasswordValues): Promise<void> {
    try {
      await forgot.mutateAsync(values);
    } catch (error) {
      applyProblemToForm(error, form.setError, ["email"]);
    }
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>{t("forgot.title")}</CardTitle>
        <CardDescription>{t("forgot.description")}</CardDescription>
      </CardHeader>
      <CardContent>
        {forgot.isSuccess ? (
          <FieldGroup>
            <Alert>
              <AlertDescription>{t("forgot.sent")}</AlertDescription>
            </Alert>
            <Link href="/login" className="text-center text-sm underline">
              {t("forgot.backToLogin")}
            </Link>
          </FieldGroup>
        ) : (
          <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
            <FieldGroup>
              {errors.root?.server && (
                <Alert variant="destructive">
                  <AlertDescription>
                    {errors.root.server.message}
                  </AlertDescription>
                </Alert>
              )}

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

              <Button type="submit" disabled={forgot.isPending}>
                {forgot.isPending ? t("common.submitting") : t("forgot.submit")}
              </Button>

              <Link href="/login" className="text-center text-sm underline">
                {t("forgot.backToLogin")}
              </Link>
            </FieldGroup>
          </form>
        )}
      </CardContent>
    </Card>
  );
}
