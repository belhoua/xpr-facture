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
import { Link, useRouter } from "@/lib/i18n/navigation";

import { applyProblemToForm, useLogin } from "../hooks/use-auth";
import { loginSchema, type LoginValues } from "../schemas/auth";

/** US-2/US-3 : connexion + « rester connecté » (30 jours). */
export function LoginForm() {
  const t = useTranslations("auth");
  const tRoot = useTranslations();
  const router = useRouter();
  const login = useLogin();

  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: "", password: "", remember: false },
  });

  const errors = form.formState.errors;

  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  async function onSubmit(values: LoginValues): Promise<void> {
    try {
      await login.mutateAsync(values);
      router.push("/dashboard");
    } catch (error) {
      // L'erreur neutre du backend arrive sous "email" (anti-énumération)
      applyProblemToForm(error, form.setError, ["email", "password"]);
    }
  }

  return (
    <Card className="w-full max-w-sm">
      <CardHeader>
        <CardTitle>{t("login.title")}</CardTitle>
        <CardDescription>{t("login.description")}</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={form.handleSubmit(onSubmit)} noValidate>
          <FieldGroup>
            {errors.root?.server && (
              <Alert variant="destructive">
                <AlertDescription>{errors.root.server.message}</AlertDescription>
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

            <Field data-invalid={errors.password !== undefined}>
              <div className="flex items-center justify-between">
                <FieldLabel htmlFor="password">{t("common.password")}</FieldLabel>
                <Link
                  href="/forgot-password"
                  className="text-muted-foreground text-sm underline"
                >
                  {t("login.forgot")}
                </Link>
              </div>
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                {...form.register("password")}
              />
              {errors.password && (
                <FieldError>{fieldError(errors.password.message)}</FieldError>
              )}
            </Field>

            <label className="flex items-center gap-2 text-sm" htmlFor="remember">
              <input
                id="remember"
                type="checkbox"
                className="accent-primary size-4"
                {...form.register("remember")}
              />
              {t("login.remember")}
            </label>

            <Button type="submit" disabled={login.isPending}>
              {login.isPending ? t("common.submitting") : t("login.submit")}
            </Button>

            <p className="text-muted-foreground text-center text-sm">
              {t("login.noAccount")}{" "}
              <Link href="/register" className="text-foreground underline">
                {t("register.title")}
              </Link>
            </p>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  );
}
