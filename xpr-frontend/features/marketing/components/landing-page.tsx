"use client";

import {
  ArrowRight,
  BadgeCheck,
  FileText,
  Languages,
  ShieldCheck,
  Wallet,
  Zap,
  type LucideIcon,
} from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { LocaleSwitcher } from "@/components/layout/locale-switcher";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { Button } from "@/components/ui/button";
import { BillingPeriodToggle } from "@/features/billing/components/billing-period-toggle";
import { PricingCard } from "@/features/billing/components/pricing-card";
import { PLANS, type BillingPeriod } from "@/features/billing/types/plan";
import { Link, useRouter } from "@/lib/i18n/navigation";

const FEATURE_ICONS: readonly LucideIcon[] = [
  FileText,
  Wallet,
  ShieldCheck,
  Languages,
];

/**
 * Page d'accueil publique (racine `/`). Vitrine commerciale de XPR Facture :
 * hero, fonctionnalités, grille tarifaire et appels à l'action.
 *
 * Elle NE fabrique aucune donnée : les prix viennent du catalogue produit
 * `PLANS` (déjà source de vérité du tunnel de souscription) et tous les
 * libellés de `messages/*.json`. Client Component pour la bascule mensuel/
 * annuel et la redirection vers l'inscription au choix d'un pack.
 */
export function LandingPage() {
  const t = useTranslations("landing");
  const tApp = useTranslations("app");
  const router = useRouter();
  const [period, setPeriod] = useState<BillingPeriod>("monthly");

  return (
    <div className="bg-background flex min-h-dvh flex-col">
      <header className="border-border/60 bg-background/80 sticky top-0 z-30 border-b backdrop-blur-md">
        <div className="mx-auto flex h-14 w-full max-w-6xl items-center gap-3 px-4 sm:px-6">
          <div className="flex items-center gap-2.5">
            <div className="bg-primary text-primary-foreground flex size-7 items-center justify-center rounded-md">
              <Zap className="size-4" aria-hidden />
            </div>
            <span className="font-heading text-sm font-semibold tracking-tight">
              {tApp("name")}
            </span>
          </div>
          <div className="ms-auto flex items-center gap-1.5">
            <LocaleSwitcher />
            <ThemeToggle />
            <Button variant="ghost" size="sm" asChild className="hidden sm:inline-flex">
              <Link href="/login">{t("nav.login")}</Link>
            </Button>
            <Button size="sm" asChild>
              <Link href="/register">{t("nav.register")}</Link>
            </Button>
          </div>
        </div>
      </header>

      <main className="flex-1">
        {/* Hero */}
        <section className="mx-auto w-full max-w-6xl px-4 py-20 text-center sm:px-6 sm:py-28">
          <span className="border-border text-muted-foreground inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium">
            <BadgeCheck className="text-primary size-3.5" aria-hidden />
            {t("hero.badge")}
          </span>
          <h1 className="font-heading mx-auto mt-6 max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
            {t("hero.title")}
          </h1>
          <p className="text-muted-foreground mx-auto mt-5 max-w-2xl text-base text-balance sm:text-lg">
            {t("hero.subtitle")}
          </p>
          <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
            <Button size="lg" asChild>
              <Link href="/register">
                {t("hero.ctaPrimary")}
                <ArrowRight aria-hidden />
              </Link>
            </Button>
            <Button size="lg" variant="outline" asChild>
              <Link href="/login">{t("hero.ctaSecondary")}</Link>
            </Button>
          </div>
        </section>

        {/* Fonctionnalités */}
        <section className="border-border/60 border-t">
          <div className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
            <div className="text-center">
              <h2 className="font-heading text-2xl font-semibold tracking-tight sm:text-3xl">
                {t("features.title")}
              </h2>
              <p className="text-muted-foreground mx-auto mt-3 max-w-2xl text-sm text-balance">
                {t("features.subtitle")}
              </p>
            </div>
            <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
              {FEATURE_ICONS.map((Icon, index) => (
                <div
                  key={index}
                  className="bg-card ring-border rounded-xl p-6 ring-1"
                >
                  <div className="bg-primary/10 text-primary flex size-10 items-center justify-center rounded-lg">
                    <Icon className="size-5" aria-hidden />
                  </div>
                  <h3 className="font-heading mt-4 text-base font-semibold">
                    {t(`features.items.${index}.title`)}
                  </h3>
                  <p className="text-muted-foreground mt-1.5 text-sm text-balance">
                    {t(`features.items.${index}.description`)}
                  </p>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Tarification */}
        <section id="pricing" className="border-border/60 border-t">
          <div className="mx-auto w-full max-w-6xl px-4 py-20 sm:px-6">
            <div className="text-center">
              <h2 className="font-heading text-2xl font-semibold tracking-tight sm:text-3xl">
                {t("pricing.title")}
              </h2>
              <p className="text-muted-foreground mx-auto mt-3 max-w-2xl text-sm text-balance">
                {t("pricing.subtitle")}
              </p>
              <div className="mt-8 flex justify-center">
                <BillingPeriodToggle value={period} onChange={setPeriod} />
              </div>
            </div>
            <div className="mt-12 grid gap-5 md:grid-cols-3">
              {PLANS.map((plan) => (
                <PricingCard
                  key={plan.id}
                  plan={plan}
                  period={period}
                  // Depuis la vitrine, choisir un pack mène à l'inscription :
                  // le tunnel de souscription reprend derrière l'authentification.
                  onSelect={() => router.push("/register")}
                />
              ))}
            </div>
          </div>
        </section>

        {/* CTA final */}
        <section className="border-border/60 border-t">
          <div className="mx-auto w-full max-w-6xl px-4 py-20 text-center sm:px-6">
            <h2 className="font-heading text-2xl font-semibold tracking-tight text-balance sm:text-3xl">
              {t("cta.title")}
            </h2>
            <p className="text-muted-foreground mx-auto mt-3 max-w-xl text-sm text-balance">
              {t("cta.subtitle")}
            </p>
            <div className="mt-8 flex flex-wrap items-center justify-center gap-3">
              <Button size="lg" asChild>
                <Link href="/register">
                  {t("cta.button")}
                  <ArrowRight aria-hidden />
                </Link>
              </Button>
            </div>
          </div>
        </section>
      </main>

      <footer className="border-border/60 border-t">
        <div className="text-muted-foreground mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 text-xs sm:px-6">
          <span>
            © {new Date().getFullYear()} {tApp("name")} — {tApp("tagline")}
          </span>
          <div className="flex items-center gap-4">
            <Link href="/login" className="hover:text-foreground">
              {t("nav.login")}
            </Link>
            <Link href="/register" className="hover:text-foreground">
              {t("nav.register")}
            </Link>
          </div>
        </div>
      </footer>
    </div>
  );
}
