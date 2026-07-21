"use client";

import { Building2, Mail, ShieldCheck, User } from "lucide-react";
import { useTranslations } from "next-intl";

import { LocaleSwitcher } from "@/components/layout/locale-switcher";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { ErrorState } from "@/components/patterns/error-state";
import { PageHeader } from "@/components/patterns/page-header";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useMe } from "@/features/auth/hooks/use-auth";
import { toApiProblem } from "@/lib/api/client";

/**
 * Écran « Paramètres » atteint depuis le menu profil. Il n'invente aucune
 * donnée : le profil et la société active viennent de `GET /auth/me`, et il
 * gère les quatre états imposés par §6 (chargement, erreur, — le « vide » ne
 * s'applique pas ici, un utilisateur connecté a toujours un profil —, succès).
 *
 * La gestion fine du profil (édition, mot de passe, membres) relève des modules
 * dédiés ; cet écran est le point d'entrée stable derrière le menu compte.
 */
export function SettingsView() {
  const t = useTranslations("settings");
  const { data, isPending, isError, error, refetch } = useMe();

  const header = <PageHeader title={t("title")} description={t("description")} />;

  if (isError) {
    return (
      <>
        {header}
        <div className="bg-card ring-border rounded-lg ring-1">
          <ErrorState
            detail={toApiProblem(error).detail}
            onRetry={() => void refetch()}
          />
        </div>
      </>
    );
  }

  return (
    <>
      {header}

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <User className="size-4" aria-hidden />
              {t("profile.title")}
            </CardTitle>
            <CardDescription>{t("profile.description")}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {isPending || !data ? (
              <ProfileSkeleton />
            ) : (
              <>
                <InfoRow icon={User} label={t("profile.name")}>
                  {data.user.name}
                </InfoRow>
                <InfoRow icon={Mail} label={t("profile.email")}>
                  <span dir="ltr">{data.user.email}</span>
                </InfoRow>
                <InfoRow icon={ShieldCheck} label={t("profile.emailStatus")}>
                  {data.user.email_verified
                    ? t("profile.verified")
                    : t("profile.unverified")}
                </InfoRow>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <Building2 className="size-4" aria-hidden />
              {t("company.title")}
            </CardTitle>
            <CardDescription>{t("company.description")}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            {isPending || !data ? (
              <ProfileSkeleton />
            ) : data.active_company ? (
              <>
                <InfoRow icon={Building2} label={t("company.legalName")}>
                  {data.active_company.legal_name}
                </InfoRow>
                <InfoRow icon={ShieldCheck} label={t("company.ice")}>
                  <span dir="ltr">{data.active_company.ice ?? "—"}</span>
                </InfoRow>
                <InfoRow icon={Building2} label={t("company.legalForm")}>
                  {data.active_company.legal_form}
                </InfoRow>
              </>
            ) : (
              <p className="text-muted-foreground text-sm">
                {t("company.none")}
              </p>
            )}
          </CardContent>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="text-base">
              {t("preferences.title")}
            </CardTitle>
            <CardDescription>{t("preferences.description")}</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap items-center gap-6">
            <div className="flex items-center gap-3">
              <span className="text-sm font-medium">
                {t("preferences.language")}
              </span>
              <LocaleSwitcher />
            </div>
            <div className="flex items-center gap-3">
              <span className="text-sm font-medium">
                {t("preferences.theme")}
              </span>
              <ThemeToggle />
            </div>
          </CardContent>
        </Card>
      </div>
    </>
  );
}

function InfoRow({
  icon: Icon,
  label,
  children,
}: {
  icon: typeof User;
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="flex items-start justify-between gap-4">
      <span className="text-muted-foreground flex items-center gap-2 text-sm">
        <Icon className="size-4 shrink-0" aria-hidden />
        {label}
      </span>
      <span className="text-sm font-medium text-end">{children}</span>
    </div>
  );
}

function ProfileSkeleton() {
  return (
    <div className="space-y-3">
      <Skeleton className="h-5 w-full" />
      <Skeleton className="h-5 w-4/5" />
      <Skeleton className="h-5 w-3/5" />
    </div>
  );
}
