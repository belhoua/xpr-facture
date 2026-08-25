"use client";

import { TriangleAlert } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import type { MissingPart } from "@/features/projects/schemas/project";
import { cn } from "@/lib/utils";

/**
 * Signalement d'une fiche projet à compléter.
 *
 * Deux formes pour un même fait, parce que les deux endroits n'ont pas la même
 * place : un BADGE dans la liste, où il faut repérer d'un coup d'œil les fiches
 * à reprendre parmi vingt lignes, et un BANDEAU dans le tiroir de détail, où il
 * y a la place de dire ce qui manque et de proposer le geste qui le corrige.
 *
 * Le critère lui-même n'est pas ici : il vit dans `isIncomplete()`
 * (`schemas/project.ts`), qui est le miroir du SQL comptant la carte « à
 * compléter ». Un composant qui déciderait lui-même de ce qui est incomplet
 * finirait par contredire le chiffre affiché au-dessus de lui.
 *
 * La teinte est celle de `partial` du jeu de statuts — l'ambre du « commencé,
 * pas fini ». Aucune couleur nouvelle n'est introduite (§11).
 */
export function IncompleteProjectBadge({ className }: { className?: string }) {
  const t = useTranslations("projects.incomplete");

  return (
    <span
      className={cn(
        "text-status-partial bg-status-partial/10 ring-status-partial/20 inline-flex h-5 w-fit items-center gap-1 rounded-4xl px-2 text-xs font-medium whitespace-nowrap ring-1",
        className,
      )}
    >
      <TriangleAlert className="size-3" aria-hidden />
      {t("badge")}
    </span>
  );
}

/**
 * Bandeau complet, avec le geste qui le fait disparaître.
 *
 * Le bouton ouvre le formulaire d'édition du projet — c'est là que se saisissent
 * la description et le reste. Signaler un manque sans offrir le moyen de le
 * combler obligerait à refermer le tiroir pour rouvrir le même projet
 * autrement.
 */
export function IncompleteProjectNotice({
  missing,
  onComplete,
}: {
  /** Ce qui manque, rendu par `missingParts()`. */
  missing: readonly MissingPart[];
  onComplete: () => void;
}) {
  const t = useTranslations("projects.incomplete");

  return (
    <div
      role="status"
      className="border-status-partial/30 bg-status-partial/10 flex flex-wrap items-start gap-3 rounded-lg border px-3 py-2.5"
    >
      <TriangleAlert
        className="text-status-partial mt-0.5 size-4 shrink-0"
        aria-hidden
      />
      <div className="min-w-40 flex-1 space-y-1">
        <p className="text-sm font-medium">{t("title")}</p>
        {/* Ce qui MANQUE, nommé, et non un rappel de la règle.
        
            Le bandeau annonçait « pas encore de description ou d'étape
            livrée » : la phrase restait la même après qu'on avait renseigné
            l'un des deux, ce qui se lit comme un écran qui n'a pas pris en
            compte la saisie. La liste raccourcit maintenant à mesure qu'on la
            traite. */}
        <ul className="text-muted-foreground space-y-0.5 text-sm">
          {missing.map((part) => (
            <li key={part}>{t(`missing.${part}`)}</li>
          ))}
        </ul>
      </div>
      <Button size="sm" variant="outline" onClick={onComplete}>
        {t("action")}
      </Button>
    </div>
  );
}
