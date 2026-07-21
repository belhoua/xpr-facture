import { MailCheck } from "lucide-react";
import type { Metadata } from "next";
import { getTranslations } from "next-intl/server";

import { CheckoutSteps } from "@/features/billing/components/checkout-steps";
import { Button } from "@/components/ui/button";
import { Link } from "@/lib/i18n/navigation";

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("pricing");

  return { title: t("success.title") };
}

/**
 * Étape 4 : confirmation. C'est l'écran demandé au cahier des charges —
 * « Vos identifiants ont été envoyés par e-mail ».
 *
 * Il ne propose PAS d'entrer directement dans l'application : le message perd
 * tout son sens si l'utilisateur peut contourner l'e-mail. Le seul chemin
 * offert est la page de connexion.
 */
export default async function SubscribeSuccessPage() {
  const t = await getTranslations("pricing");

  const stepLabels = {
    account: t("steps.account"),
    plan: t("steps.plan"),
    payment: t("steps.payment"),
    done: t("steps.done"),
  };

  return (
    <div className="mx-auto max-w-2xl">
      <CheckoutSteps current="done" labels={stepLabels} />

      <div className="mt-16 flex flex-col items-center text-center">
        <div className="bg-status-paid/10 text-status-paid ring-status-paid/20 flex size-14 items-center justify-center rounded-full ring-1">
          <MailCheck className="size-7" aria-hidden />
        </div>

        <h1 className="font-heading mt-6 text-2xl font-semibold tracking-tight text-balance">
          {t("success.title")}
        </h1>
        <p className="text-muted-foreground mt-3 max-w-md text-sm text-balance">
          {t("success.description")}
        </p>

        <Button asChild size="lg" className="mt-8">
          <Link href="/login">{t("success.goToLogin")}</Link>
        </Button>

        <p className="text-muted-foreground mt-6 max-w-sm text-xs text-balance">
          {t("success.checkSpam")}
        </p>
      </div>
    </div>
  );
}
