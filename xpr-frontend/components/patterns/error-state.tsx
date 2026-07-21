"use client";

import { AlertTriangle, RefreshCw } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

/**
 * État d'erreur (4e des états imposés par §6). Affiche le `detail` du Problem
 * Details RFC 9457 renvoyé par l'API quand il existe — jamais la stack trace,
 * jamais un « Something went wrong » opaque.
 */
export function ErrorState({
  detail,
  onRetry,
  className,
}: {
  detail?: string;
  onRetry?: () => void;
  className?: string;
}) {
  const t = useTranslations("common");

  return (
    <div
      role="alert"
      className={cn(
        "flex flex-col items-center justify-center px-6 py-16 text-center",
        className,
      )}
    >
      <div className="bg-destructive/10 text-destructive ring-destructive/20 flex size-11 items-center justify-center rounded-lg ring-1">
        <AlertTriangle className="size-5" aria-hidden />
      </div>
      <p className="mt-4 text-sm font-medium">{t("error")}</p>
      {detail ? (
        <p className="text-muted-foreground mt-1 max-w-sm text-sm text-balance">
          {detail}
        </p>
      ) : null}
      {onRetry ? (
        <Button variant="outline" size="sm" className="mt-5" onClick={onRetry}>
          <RefreshCw aria-hidden />
          {t("retry")}
        </Button>
      ) : null}
    </div>
  );
}
