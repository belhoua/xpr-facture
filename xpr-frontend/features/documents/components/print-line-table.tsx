"use client";

import { useLocale, useTranslations } from "next-intl";

import { Cell } from "@/features/documents/components/print-primitives";
import type { DocumentItem } from "@/features/documents/schemas/document";
import { formatAmount, formatQuantity } from "@/lib/format";

/**
 * Tableau des lignes d'un document imprimé (devis, facture).
 *
 * Les colonnes sont DÉCLARÉES UNE FOIS et servent à la fois l'en-tête, le
 * `colgroup` et le corps : un `<td>` ne peut plus glisser sous le mauvais
 * `<th>`. C'est la raison d'être de ce fichier — deux listes de cellules
 * écrites à la main dans deux gabarits divergent au premier ajout de colonne,
 * et c'est exactement ce qui était arrivé entre le devis (6 colonnes) et la
 * facture (8).
 *
 * Aucun montant n'est recalculé ici : tous viennent du serveur en centimes
 * entiers (§7). `formatAmount` divise par 100 au tout dernier moment.
 */
export function DocumentLineTable({ items }: { items: readonly DocumentItem[] }) {
  const t = useTranslations("documents");
  const locale = useLocale();

  const columns = documentColumns(t, locale);

  return (
    <table className="border-foreground/60 mt-4 w-full table-fixed border-collapse border text-[9.5pt]">
      <colgroup>
        {columns.map((column) => (
          <col key={column.key} style={{ width: column.width }} />
        ))}
      </colgroup>

      <thead>
        {/* En-têtes TOUJOURS centrés, quel que soit l'alignement des valeurs :
            c'est le modèle du client, et un intitulé calé à droite au-dessus
            d'une colonne large se lit comme s'il appartenait à la voisine. */}
        <tr className="border-foreground/60 border font-bold">
          {columns.map((column) => (
            <Cell key={column.key} as="th">
              {column.header}
            </Cell>
          ))}
        </tr>
      </thead>

      <tbody>
        {items.map((item) => (
          <tr key={item.id} className="border-foreground/60 border align-top">
            {columns.map((column) => (
              <Cell
                key={column.key}
                align={column.align}
                // `.amount` ne pose AUCUN `display` (cf. globals.css) : sur un
                // `<td>`, il en changerait la nature et sortirait la cellule de
                // la grille.
                className={column.numeric ? "amount" : undefined}
              >
                {column.cell(item)}
              </Cell>
            ))}
          </tr>
        ))}

        {items.length === 0 ? (
          <tr>
            <Cell colSpan={columns.length} className="py-8 text-center">
              {t("form.noLines")}
            </Cell>
          </tr>
        ) : null}
      </tbody>
    </table>
  );
}

type PrintColumn = {
  key: string;
  header: string;
  /** Largeur imposée : le tableau est `table-fixed`, le contenu ne l'élargit pas. */
  width: string;
  align: "start" | "center" | "end";
  /** Montant ou taux : chasse fixe, isolé du sens de lecture, jamais replié. */
  numeric?: boolean;
  cell: (item: DocumentItem) => React.ReactNode;
};

/**
 * Les huit colonnes du détail, dans l'ordre imprimé.
 *
 * Les largeurs sont dimensionnées sur le CONTENU LE PLUS LONG attendu : un
 * montant à sept chiffres (« 1 250 000,00 ») doit tenir sur une seule ligne,
 * sinon il se replie ou déborde sur la colonne voisine et le tableau devient
 * illisible. La désignation prend le reste : c'est la seule colonne qu'on
 * accepte de voir courir sur plusieurs lignes.
 */
function documentColumns(
  t: (key: string) => string,
  locale: string,
): readonly PrintColumn[] {
  return [
    {
      key: "rank",
      header: t("print.columns.rank"),
      width: "5%",
      align: "center",
      // `position` fait foi (le serveur trie dessus) et part de zéro, d'où le
      // décalage — se fier à l'index du tableau ferait dépendre un numéro
      // imprimé de l'ordre de rendu.
      cell: (item) => item.position + 1,
    },
    {
      key: "designation",
      header: t("print.columns.designation"),
      width: "30%",
      align: "start",
      cell: (item) => <Designation item={item} />,
    },
    {
      key: "unit",
      header: t("print.columns.unit"),
      // 6 % et non 5 : « unité » est l'unité la plus longue du catalogue et
      // touchait les filets.
      width: "6%",
      align: "center",
      cell: (item) => item.unit ?? "—",
    },
    {
      key: "quantity",
      header: t("print.columns.quantity"),
      width: "7%",
      align: "center",
      numeric: true,
      cell: (item) => formatQuantity(Number(item.quantity), locale),
    },
    {
      key: "unitPrice",
      header: t("print.columns.unitPrice"),
      width: "13%",
      align: "end",
      numeric: true,
      cell: (item) => formatAmount(item.unitPriceCents, locale),
    },
    {
      key: "lineTotal",
      header: t("print.columns.lineTotal"),
      width: "14%",
      align: "end",
      numeric: true,
      // Base HT de la ligne, remise déduite : c'est elle qui porte la TVA et
      // qui s'additionne au « Total HT » du pied. Afficher le brut ici ferait
      // un tableau dont la colonne ne totalise pas le pied de page.
      cell: (item) => formatAmount(item.subtotalCents, locale),
    },
    {
      key: "vat",
      header: t("print.columns.vat"),
      width: "7%",
      align: "center",
      numeric: true,
      // Taux FIGÉ sur la ligne à la saisie (§3), jamais celui du référentiel du
      // jour : une facture réimprimée après une réforme du barème doit
      // ressortir identique. `taxRate` arrive en décimal exact (chaîne) :
      // « 20.00 » s'imprime « 20 % ».
      cell: (item) => {
        const rate = Number(item.taxRate);

        return Number.isFinite(rate) ? `${formatQuantity(rate, locale)} %` : "—";
      },
    },
    {
      key: "totalIncl",
      header: t("print.columns.totalIncl"),
      width: "18%",
      align: "end",
      numeric: true,
      cell: (item) => formatAmount(item.totalCents, locale),
    },
  ];
}

/** Libellé de la prestation, suivi de ses sous-points en liste à puces. */
function Designation({ item }: { item: DocumentItem }) {
  // Les sous-points d'une prestation sont saisis un par ligne dans la
  // description ; le modèle les puce. Les lignes vides sont écartées : un
  // retour à la ligne isolé ne mérite pas sa puce.
  const bullets = (item.description ?? "")
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line.length > 0);

  return (
    <>
      {/* `break-words` : un libellé sans espace (une référence produit) doit se
          couper dans sa colonne au lieu de la déborder — `table-fixed` ne
          l'élargira pas pour lui. */}
      <span className="font-bold break-words uppercase">{item.label}</span>
      {bullets.length > 0 ? (
        <ul className="mt-1 space-y-0.5">
          {bullets.map((bullet, index) => (
            <li key={`${index}-${bullet}`} className="flex gap-1.5">
              <span aria-hidden>•</span>
              <span className="min-w-0 flex-1 break-words">{bullet}</span>
            </li>
          ))}
        </ul>
      ) : null}
    </>
  );
}
