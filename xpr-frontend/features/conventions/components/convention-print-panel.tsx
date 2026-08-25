"use client";

import { RotateCcw, X } from "lucide-react";
import { useTranslations } from "next-intl";

import { Button } from "@/components/ui/button";
import { Field, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import type { ConventionPrintDraft } from "@/features/conventions/print-draft";

/**
 * Panneau de personnalisation du contrat, à côté de la feuille.
 *
 * Il ne s'imprime pas — il porte `no-print`, et le sélecteur `aside` de la
 * feuille de style d'impression le vise déjà. À partir de `xl` il flotte le
 * long du bord, la feuille A4 restant lisible à sa gauche : c'est ce qui rend
 * l'aperçu utile, on voit le document changer pendant qu'on tape. En dessous,
 * il reprend sa place dans le flux, au-dessus du document — un panneau flottant
 * sur un écran étroit recouvrirait ce qu'il sert à régler.
 *
 * Le seuil est 1400 px et non un palier Tailwind : c'est la largeur à partir de
 * laquelle la marge laissée par une feuille A4 centrée (794 px) dépasse celle du
 * panneau. `xl` (1280 px) le ferait mordre sur le texte du contrat.
 *
 * Aucun bouton « Enregistrer » : rien de ce qui se règle ici ne part vers
 * l'API. `ConventionPrintDraft` dit pourquoi, et le panneau le répète en clair
 * sous son titre — un formulaire qui ressemble à celui de la saisie doit
 * annoncer qu'il n'enregistre pas.
 */
export function ConventionPrintPanel({
  draft,
  onChange,
  onReset,
  onClose,
}: {
  draft: ConventionPrintDraft;
  onChange: (patch: Partial<ConventionPrintDraft>) => void;
  onReset: () => void;
  onClose: () => void;
}) {
  const t = useTranslations("conventions");

  return (
    <aside
      aria-label={t("print.panel.title")}
      className="no-print bg-card mb-4 rounded-lg border p-4 shadow-sm min-[1400px]:fixed min-[1400px]:end-4 min-[1400px]:top-4 min-[1400px]:z-40 min-[1400px]:mb-0 min-[1400px]:max-h-[calc(100dvh-2rem)] min-[1400px]:w-80 min-[1400px]:overflow-y-auto print:hidden"
    >
      <div className="mb-3 flex items-start justify-between gap-2">
        <div>
          <h2 className="font-heading text-base font-medium">
            {t("print.panel.title")}
          </h2>
          <p className="text-muted-foreground mt-0.5 text-xs">
            {t("print.panel.description")}
          </p>
        </div>
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={onClose}
          aria-label={t("print.panel.close")}
        >
          <X className="size-4" aria-hidden />
        </Button>
      </div>

      <div className="space-y-5">
        <Group title={t("form.sections.file")}>
          <Field>
            <FieldLabel htmlFor="print-dossierNumber">
              {t("form.dossierNumber")}
            </FieldLabel>
            {/* Le modèle imprime des pointillés tant que le dossier n'est pas
                déposé : ce champ est ce qui les remplace. */}
            <Input
              id="print-dossierNumber"
              placeholder="0003439/AK/26"
              value={draft.dossierNumber}
              onChange={(event) => onChange({ dossierNumber: event.target.value })}
            />
          </Field>

          <div className="grid gap-3 sm:grid-cols-2 min-[1400px]:grid-cols-1">
            <Field>
              <FieldLabel htmlFor="print-issueCity">
                {t("form.issueCity")}
              </FieldLabel>
              <Input
                id="print-issueCity"
                placeholder={t("form.issueCityPlaceholder")}
                value={draft.issueCity}
                onChange={(event) => onChange({ issueCity: event.target.value })}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="print-issuedAt">{t("form.issuedAt")}</FieldLabel>
              <Input
                id="print-issuedAt"
                type="date"
                value={draft.issuedAt}
                onChange={(event) => onChange({ issuedAt: event.target.value })}
              />
            </Field>
          </div>
        </Group>

        <Group title={t("form.sections.owner")}>
          <Field>
            <FieldLabel htmlFor="print-ownerName">{t("form.ownerName")}</FieldLabel>
            <Input
              id="print-ownerName"
              value={draft.ownerName}
              onChange={(event) => onChange({ ownerName: event.target.value })}
            />
          </Field>

          <div className="grid gap-3 sm:grid-cols-2 min-[1400px]:grid-cols-1">
            <Field>
              <FieldLabel htmlFor="print-ownerIce">{t("form.ownerIce")}</FieldLabel>
              <Input
                id="print-ownerIce"
                inputMode="numeric"
                value={draft.ownerIce}
                onChange={(event) => onChange({ ownerIce: event.target.value })}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="print-ownerRc">{t("form.ownerRc")}</FieldLabel>
              <Input
                id="print-ownerRc"
                value={draft.ownerRc}
                onChange={(event) => onChange({ ownerRc: event.target.value })}
              />
            </Field>
          </div>

          <Field>
            <FieldLabel htmlFor="print-ownerAddress">
              {t("form.ownerAddress")}
            </FieldLabel>
            <Textarea
              id="print-ownerAddress"
              rows={2}
              value={draft.ownerAddress}
              onChange={(event) => onChange({ ownerAddress: event.target.value })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="print-ownerNote">
              {t("print.panel.ownerNote")}
            </FieldLabel>
            <Textarea
              id="print-ownerNote"
              rows={2}
              placeholder={t("print.panel.ownerNotePlaceholder")}
              value={draft.ownerNote}
              onChange={(event) => onChange({ ownerNote: event.target.value })}
            />
          </Field>
        </Group>

        <Group title={t("form.sections.project")}>
          <Field>
            <FieldLabel htmlFor="print-projectDescription">
              {t("form.projectDescription")}
            </FieldLabel>
            <Textarea
              id="print-projectDescription"
              rows={3}
              value={draft.projectDescription}
              onChange={(event) =>
                onChange({ projectDescription: event.target.value })
              }
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="print-projectAddress">
              {t("form.projectAddress")}
            </FieldLabel>
            <Input
              id="print-projectAddress"
              value={draft.projectAddress}
              onChange={(event) => onChange({ projectAddress: event.target.value })}
            />
          </Field>

          <Field>
            <FieldLabel htmlFor="print-projectTitleDeed">
              {t("form.projectTitleDeed")}
            </FieldLabel>
            <Input
              id="print-projectTitleDeed"
              placeholder="138618/04"
              value={draft.projectTitleDeed}
              onChange={(event) => onChange({ projectTitleDeed: event.target.value })}
            />
          </Field>
        </Group>

        <Group title={t("form.sections.mission")}>
          <Field>
            <FieldLabel htmlFor="print-lots">{t("form.lots")}</FieldLabel>
            {/* Une ligne = une puce de l'article 1 : ajouter un lot, c'est une
                ligne de plus, en retirer un, c'est une ligne de moins. */}
            <Textarea
              id="print-lots"
              rows={5}
              placeholder={t("form.lotsPlaceholder")}
              value={draft.lots}
              onChange={(event) => onChange({ lots: event.target.value })}
            />
            <p className="text-muted-foreground text-xs">{t("form.lotsHint")}</p>
          </Field>

          <Field>
            <FieldLabel htmlFor="print-executionDelay">
              {t("form.executionDelay")}
            </FieldLabel>
            <Input
              id="print-executionDelay"
              placeholder={t("form.executionDelayPlaceholder")}
              value={draft.executionDelay}
              onChange={(event) => onChange({ executionDelay: event.target.value })}
            />
          </Field>
        </Group>

        <Group title={t("print.panel.clauses")}>
          <Field>
            <FieldLabel htmlFor="print-notes" className="sr-only">
              {t("print.panel.clauses")}
            </FieldLabel>
            <Textarea
              id="print-notes"
              rows={4}
              value={draft.notes}
              onChange={(event) => onChange({ notes: event.target.value })}
            />
            <p className="text-muted-foreground text-xs">
              {t("print.panel.clausesHint")}
            </p>
          </Field>
        </Group>
      </div>

      <Button variant="outline" className="mt-5 w-full" onClick={onReset}>
        <RotateCcw className="size-4" aria-hidden />
        {t("print.panel.reset")}
      </Button>
    </aside>
  );
}

/**
 * Regroupement titré, comme dans le formulaire de saisie : le panneau couvre
 * les mêmes cinq domaines, et une colonne de quinze champs sans repère se
 * relit mal — surtout dans une colonne étroite.
 */
function Group({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-3">
      <h3 className="text-muted-foreground border-b pb-1 text-xs font-semibold tracking-wide uppercase">
        {title}
      </h3>
      {children}
    </section>
  );
}
