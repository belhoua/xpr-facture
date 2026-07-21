"use client";

import { Calculator as CalculatorIcon, Delete } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useState } from "react";

import { Button } from "@/components/ui/button";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import { cn } from "@/lib/utils";

type Operator = "+" | "−" | "×" | "÷";

/** Corrige les artefacts de flottant (0,1 + 0,2) sans dépendance externe. */
function round(value: number): number {
  return Math.round((value + Number.EPSILON) * 1e10) / 1e10;
}

function compute(a: number, operator: Operator, b: number): number {
  switch (operator) {
    case "+":
      return round(a + b);
    case "−":
      return round(a - b);
    case "×":
      return round(a * b);
    case "÷":
      return b === 0 ? NaN : round(a / b);
  }
}

/**
 * Calculatrice de poche pour les petits calculs de facturation — accessible
 * partout depuis la topbar. Autonome (aucune lib), pilotable au clavier tant
 * que le popover a le focus. Les calculs restent en mémoire volatile : c'est
 * un utilitaire d'appoint, pas une source de données métier.
 */
export function Calculator() {
  const t = useTranslations("calculator");
  const locale = useLocale();

  const [display, setDisplay] = useState("0");
  const [accumulator, setAccumulator] = useState<number | null>(null);
  const [pendingOp, setPendingOp] = useState<Operator | null>(null);
  // Le prochain chiffre REMPLACE l'affichage (après un opérateur ou un résultat).
  const [overwrite, setOverwrite] = useState(true);

  const localeTag = locale === "ar" ? "ar-MA-u-nu-latn" : `${locale}-MA`;

  const inputDigit = (digit: string) => {
    setDisplay((current) => {
      if (overwrite) return digit;
      if (current === "0") return digit;
      // Garde-fou de longueur : au-delà, l'affichage déborderait.
      if (current.replace(/[.\-]/g, "").length >= 12) return current;
      return current + digit;
    });
    setOverwrite(false);
  };

  const inputDot = () => {
    if (overwrite) {
      setDisplay("0.");
      setOverwrite(false);
      return;
    }
    setDisplay((current) => (current.includes(".") ? current : current + "."));
  };

  const clearAll = () => {
    setDisplay("0");
    setAccumulator(null);
    setPendingOp(null);
    setOverwrite(true);
  };

  const backspace = () => {
    if (overwrite) return;
    setDisplay((current) => {
      const next = current.slice(0, -1);
      return next === "" || next === "-" ? "0" : next;
    });
  };

  const percent = () => {
    setDisplay((current) => String(round(Number(current) / 100)));
    setOverwrite(true);
  };

  const toggleSign = () => {
    setDisplay((current) =>
      current === "0" ? current : String(round(Number(current) * -1)),
    );
  };

  const chooseOp = (operator: Operator) => {
    const value = Number(display);

    if (accumulator !== null && pendingOp !== null && !overwrite) {
      const result = compute(accumulator, pendingOp, value);
      setAccumulator(result);
      setDisplay(formatResult(result));
    } else {
      setAccumulator(value);
    }

    setPendingOp(operator);
    setOverwrite(true);
  };

  const equals = () => {
    if (accumulator === null || pendingOp === null) return;
    const result = compute(accumulator, pendingOp, Number(display));
    setDisplay(formatResult(result));
    setAccumulator(null);
    setPendingOp(null);
    setOverwrite(true);
  };

  function formatResult(value: number): string {
    if (!Number.isFinite(value)) return "∞";
    return String(value);
  }

  /** Affichage lisible : séparateurs de milliers sans casser une saisie en cours. */
  const pretty = (() => {
    if (!Number.isFinite(Number(display))) return "∞";
    if (display.endsWith(".") || display.includes("e")) return display;
    const [intPart, decPart] = display.split(".");
    const grouped = new Intl.NumberFormat(localeTag, {
      maximumFractionDigits: 0,
    }).format(Number(intPart ?? "0"));
    return decPart !== undefined ? `${grouped},${decPart}` : grouped;
  })();

  const onKeyDown = (event: React.KeyboardEvent) => {
    const { key } = event;
    if (key >= "0" && key <= "9") {
      inputDigit(key);
    } else if (key === ".") {
      inputDot();
    } else if (key === "+") {
      chooseOp("+");
    } else if (key === "-") {
      chooseOp("−");
    } else if (key === "*") {
      chooseOp("×");
    } else if (key === "/") {
      event.preventDefault();
      chooseOp("÷");
    } else if (key === "%") {
      percent();
    } else if (key === "Enter" || key === "=") {
      event.preventDefault();
      equals();
    } else if (key === "Backspace") {
      backspace();
    } else if (key === "Escape") {
      clearAll();
    } else {
      return;
    }
  };

  const opActive = (operator: Operator) =>
    pendingOp === operator && overwrite;

  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" aria-label={t("open")}>
          <CalculatorIcon aria-hidden />
        </Button>
      </PopoverTrigger>

      <PopoverContent
        align="end"
        className="w-72"
        onKeyDown={onKeyDown}
        onOpenAutoFocus={(event) => {
          // Garder le focus sur le contenu pour capter le clavier, sans le
          // renvoyer sur un bouton précis (aucun n'est « primaire »).
          event.preventDefault();
          (event.currentTarget as HTMLElement).focus();
        }}
        tabIndex={-1}
      >
        <div className="mb-3 flex items-center justify-between">
          <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            {t("title")}
          </span>
          <CalculatorIcon className="text-muted-foreground size-3.5" aria-hidden />
        </div>

        {/* Écran : montant courant en grand, opération en attente au-dessus. */}
        <div className="bg-muted/50 ring-border mb-3 rounded-lg px-3 py-2.5 ring-1">
          <div className="text-muted-foreground h-4 text-end text-xs tabular-nums">
            {accumulator !== null && pendingOp
              ? `${new Intl.NumberFormat(localeTag).format(accumulator)} ${pendingOp}`
              : " "}
          </div>
          <div
            className="truncate text-end text-2xl font-semibold tabular-nums"
            aria-live="polite"
          >
            {pretty}
          </div>
        </div>

        <div className="grid grid-cols-4 gap-1.5">
          <Key onClick={clearAll} variant="muted">
            AC
          </Key>
          <Key onClick={toggleSign} variant="muted" aria-label="±">
            ±
          </Key>
          <Key onClick={percent} variant="muted">
            %
          </Key>
          <Key onClick={() => chooseOp("÷")} variant="accent" active={opActive("÷")}>
            ÷
          </Key>

          <Key onClick={() => inputDigit("7")}>7</Key>
          <Key onClick={() => inputDigit("8")}>8</Key>
          <Key onClick={() => inputDigit("9")}>9</Key>
          <Key onClick={() => chooseOp("×")} variant="accent" active={opActive("×")}>
            ×
          </Key>

          <Key onClick={() => inputDigit("4")}>4</Key>
          <Key onClick={() => inputDigit("5")}>5</Key>
          <Key onClick={() => inputDigit("6")}>6</Key>
          <Key onClick={() => chooseOp("−")} variant="accent" active={opActive("−")}>
            −
          </Key>

          <Key onClick={() => inputDigit("1")}>1</Key>
          <Key onClick={() => inputDigit("2")}>2</Key>
          <Key onClick={() => inputDigit("3")}>3</Key>
          <Key onClick={() => chooseOp("+")} variant="accent" active={opActive("+")}>
            +
          </Key>

          <Key onClick={() => inputDigit("0")} className="col-span-2">
            0
          </Key>
          <Key onClick={inputDot}>,</Key>
          <Key onClick={equals} variant="primary" aria-label="=">
            =
          </Key>
        </div>

        <button
          type="button"
          onClick={backspace}
          className="text-muted-foreground hover:text-foreground mt-2 flex w-full items-center justify-center gap-1.5 rounded-md py-1.5 text-xs transition-colors"
        >
          <Delete className="size-3.5" aria-hidden />
          {t("clear")}
        </button>
      </PopoverContent>
    </Popover>
  );
}

/** Touche de la calculatrice — variantes visuelles cohérentes avec le design. */
function Key({
  children,
  onClick,
  variant = "digit",
  active = false,
  className,
  ...props
}: {
  children: React.ReactNode;
  onClick: () => void;
  variant?: "digit" | "muted" | "accent" | "primary";
  active?: boolean;
  className?: string;
} & Omit<React.ComponentProps<"button">, "onClick">) {
  return (
    <button
      type="button"
      onClick={onClick}
      tabIndex={-1}
      className={cn(
        "flex h-11 items-center justify-center rounded-lg text-base font-medium transition-all active:scale-95",
        "focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-none",
        variant === "digit" &&
          "bg-muted/40 hover:bg-muted text-foreground",
        variant === "muted" &&
          "bg-muted/40 hover:bg-muted text-muted-foreground",
        variant === "accent" &&
          "bg-primary/10 text-primary hover:bg-primary/15",
        variant === "primary" &&
          "bg-primary text-primary-foreground hover:bg-primary/90",
        active && "ring-primary ring-2",
        className,
      )}
      {...props}
    >
      {children}
    </button>
  );
}
