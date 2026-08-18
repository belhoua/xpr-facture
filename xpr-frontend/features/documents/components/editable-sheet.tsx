"use client";

import { memo, type ReactNode } from "react";

/**
 * Feuille A4 ÉDITABLE À LA MANIÈRE D'UN TRAITEMENT DE TEXTE.
 *
 * Le document entier est un `contentEditable` : on clique dans n'importe quel
 * texte, on corrige, on ajoute un paragraphe avec `Entrée`, on supprime une
 * ligne de tableau en la sélectionnant. Aucun champ de formulaire, aucun bouton
 * d'action — le navigateur est l'éditeur, comme dans Word.
 *
 * ── Pourquoi `memo` avec un comparateur TOUJOURS VRAI ──────────────────────
 *
 * C'est la pièce maîtresse, et elle n'est pas une optimisation.
 *
 * Une fois la saisie commencée, le DOM sous cette feuille n'appartient plus à
 * React : c'est l'utilisateur qui l'a écrit. Or React, à la première
 * reconciliation venue, réimposerait l'arbre qu'il croit juste — et effacerait
 * la frappe en cours. Il suffirait d'un refetch d'arrière-plan de TanStack
 * Query, d'un retour de focus sur l'onglet, d'un changement de thème.
 *
 * `memo(Sheet, () => true)` fige donc la sous-arborescence : elle est rendue
 * UNE FOIS, au montage, et plus jamais reconciliée. Les `children` sont
 * évalués par le parent à chaque rendu, mais leur résultat est ignoré.
 *
 * Conséquence assumée, et c'est le prix de l'édition libre : rien de ce que le
 * serveur renverra ensuite n'atteindra plus l'écran. Recharger la page est le
 * seul moyen de revenir au document enregistré — et c'est aussi ce qui rend
 * l'opération sûre : rien de ce qui est tapé ici ne part vers l'API.
 *
 * ── Ce que la feuille ne fait plus ─────────────────────────────────────────
 *
 * Les totaux ne se recalculent PAS. Corriger un prix à la main ne change ni le
 * total HT, ni la TVA, ni le montant en toutes lettres : il faut les reprendre
 * un à un, comme dans un document Word. C'est la contrepartie directe de
 * l'absence d'état React — un recalcul supposerait de relire ce que
 * l'utilisateur a tapé, donc de le réécrire, donc de déplacer son curseur.
 *
 * Sur une facture ÉMISE, la page permet donc de produire un papier qui ne
 * correspond plus à la pièce enregistrée, ni à ses propres lignes. Le §3 fait
 * corriger une facture émise par une pièce rattachée, jamais par une retouche
 * à l'impression.
 */
function Sheet({ children }: { children: ReactNode }) {
  return (
    <article
      contentEditable
      // React avertit dès qu'un nœud contrôlé devient éditable : c'est
      // exactement ce qu'on fait ici, et en connaissance de cause.
      suppressContentEditableWarning
      // Les soulignements rouges du correcteur parasitent un document
      // commercial — raisons sociales, références, unités abrégées en sont
      // constellés — sans rien apporter à sa relecture.
      spellCheck={false}
      className="print-sheet editable-sheet bg-card ring-border mx-auto w-full max-w-[210mm] p-[14mm] text-[11pt] leading-snug ring-1 print:ring-0"
    >
      {children}
    </article>
  );
}

export const EditableSheet = memo(Sheet, () => true);
