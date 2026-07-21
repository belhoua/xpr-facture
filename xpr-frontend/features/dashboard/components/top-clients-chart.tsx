"use client";

import { Users } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { EmptyState } from "@/components/patterns/empty-state";
import type { TopClient } from "@/features/dashboard/schemas/stats";
import { formatMoney } from "@/lib/format";

/**
 * Cinq premiers clients par chiffre d'affaires, en « bar list » : une ligne par
 * client, portant son nom, sa barre et son montant.
 *
 * PAS de graphique Recharts ici, et c'est délibéré. Un BarChart horizontal
 * imposait un axe des ordonnées large pour des raisons sociales longues
 * (« Cabinet Comptable Tazi & Associés ») : les étiquettes se cassaient sur
 * deux lignes, se chevauchaient, et répétaient la liste chiffrée placée en
 * dessous. Cinq barres ne justifient pas une librairie de graphiques — du HTML
 * mis en forme est ici plus lisible, s'inverse tout seul en RTL grâce aux
 * propriétés logiques, et reste lisible par un lecteur d'écran.
 *
 * UNE SEULE SÉRIE : une seule teinte (`--chart-1`), pas de légende — le titre
 * nomme la mesure. La couleur ne code rien, c'est la LONGUEUR qui porte
 * l'information ; peindre chaque barre différemment ferait croire à des
 * catégories et lierait l'identité au rang, qui change à chaque période.
 */
export function TopClientsChart({ data }: { data: readonly TopClient[] }) {
  const t = useTranslations("dashboard.topClients");
  const locale = useLocale();

  // Échelle relative au PREMIER du classement : la barre la plus longue occupe
  // toute la largeur, ce qui rend les écarts lisibles même quand les montants
  // sont proches. Garde-fou à 1 pour ne pas diviser par zéro.
  const maxCents = Math.max(1, ...data.map((row) => row.totalCents));

  return (
    <div className="bg-card ring-border rounded-lg p-4 ring-1">
      <div className="mb-4">
        <h2 className="font-heading text-sm font-medium">{t("title")}</h2>
        <p className="text-muted-foreground mt-0.5 text-xs">
          {t("description")}
        </p>
      </div>

      {data.length === 0 ? (
        <EmptyState
          icon={Users}
          title={t("empty.title")}
          description={t("empty.description")}
        />
      ) : (
        <ol className="space-y-3">
          {data.map((row) => {
            const share = Math.round((row.totalCents / maxCents) * 100);

            return (
              <li key={row.name} className="group/row">
                <div className="flex items-baseline justify-between gap-3 text-sm">
                  <span className="min-w-0 truncate font-medium">
                    {row.name}
                  </span>
                  <span className="flex shrink-0 items-baseline gap-2">
                    <span className="amount text-muted-foreground text-xs">
                      {t("invoiceCount", { count: row.invoiceCount })}
                    </span>
                    <span className="amount font-medium">
                      {formatMoney(row.totalCents, locale)}
                    </span>
                  </span>
                </div>

                {/* Piste + barre. `rounded-e` : extrémité arrondie côté valeur,
                    départ carré — la barre reste ancrée à sa ligne de base. */}
                <div
                  className="bg-muted mt-1.5 h-2 w-full overflow-hidden rounded-sm"
                  role="img"
                  aria-label={`${row.name} : ${formatMoney(row.totalCents, locale)}`}
                >
                  <div
                    className="h-full rounded-e-[4px] transition-[width] duration-300"
                    style={{
                      width: `${share}%`,
                      // Largeur minimale visible : un client à 11 MAD face à un
                      // autre à 3 M produirait une barre de 0 px, donc invisible.
                      minWidth: row.totalCents > 0 ? "3px" : undefined,
                      background: "var(--color-chart-1)",
                    }}
                  />
                </div>
              </li>
            );
          })}
        </ol>
      )}
    </div>
  );
}
