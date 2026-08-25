"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Plus, Trash2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useState } from "react";

import { ErrorState } from "@/components/patterns/error-state";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Skeleton } from "@/components/ui/skeleton";
import {
  addDeliverable,
  deleteDeliverable,
  fetchProject,
  projectKeys,
  updateProjectProgress,
} from "@/features/projects/api/projects";
import { IncompleteProjectNotice } from "@/features/projects/components/incomplete-project-notice";
import { ProgressBar } from "@/features/projects/components/progress-bar";
import { ProjectStatusBadge } from "@/features/projects/components/project-status-badge";
import {
  isProgressEditable,
  missingParts,
  PROJECT_STATUSES,
  type Project,
  type ProjectStatus,
} from "@/features/projects/schemas/project";
import { toApiProblem } from "@/lib/api/client";
import { formatDate } from "@/lib/format";

/**
 * Détail d'un projet : son avancement, et les livrables remis au client.
 *
 * Deux sections, deux gestes distincts. L'AVANCEMENT est un formulaire à
 * valider — le statut et le pourcentage se décident ensemble, et pousser
 * chaque frappe du curseur produirait une requête par point de pourcentage.
 * Les LIVRABLES s'ajoutent un par un, chacun étant un fait daté indépendant.
 *
 * Le serveur reste juge : il répond 409 si l'on tente de faire avancer un
 * projet annulé, et l'on réaffiche SON message plutôt que d'en inventer un.
 */
export function ProjectDetailSheet({
  projectId,
  onOpenChange,
  onEdit,
}: {
  projectId: string | null;
  onOpenChange: (open: boolean) => void;
  onEdit: (project: Project) => void;
}) {
  const t = useTranslations("projects");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();

  const [actionError, setActionError] = useState<string | null>(null);

  // État local du formulaire d'avancement. Il ne peut pas être dérivé de la
  // requête : l'utilisateur bouge le curseur avant d'enregistrer, et relire la
  // valeur serveur à chaque rendu annulerait sa saisie.
  const [draftStatus, setDraftStatus] = useState<ProjectStatus>("in_progress");
  const [draftProgress, setDraftProgress] = useState(0);

  const [deliverableTitle, setDeliverableTitle] = useState("");
  const [deliverableDate, setDeliverableDate] = useState("");

  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: projectKeys.detail(projectId ?? ""),
    queryFn: () => fetchProject(projectId ?? ""),
    enabled: projectId !== null,
  });

  // Réaligne le brouillon sur le projet chargé — à l'ouverture du tiroir, et
  // après chaque enregistrement réussi. Dépendre de `data` et non du seul
  // `projectId` : sans cela, rouvrir le même projet après une modification
  // ré-afficherait les valeurs de la session précédente.
  useEffect(() => {
    if (data) {
      setDraftStatus(data.status);
      setDraftProgress(data.progressPercentage);
    }
  }, [data]);

  /** Rafraîchit la liste ET le détail : les deux caches parlent du même objet. */
  const settle = async (saved: Project) => {
    queryClient.setQueryData(projectKeys.detail(saved.id), saved);
    await queryClient.invalidateQueries({ queryKey: projectKeys.all });
    setActionError(null);
  };

  const fail = (cause: unknown) => {
    const problem = toApiProblem(cause);
    setActionError(problem.detail ?? problem.title);
  };

  const progressMutation = useMutation({
    mutationFn: ({ id, ...progress }: { id: string; status: string; progressPercentage: number }) =>
      updateProjectProgress(id, progress),
    onSuccess: settle,
    onError: fail,
  });

  const addMutation = useMutation({
    mutationFn: ({ id, title, deliveryDate }: { id: string; title: string; deliveryDate: string }) =>
      addDeliverable(id, { title, deliveryDate, notes: "" }),
    onSuccess: async () => {
      // La réponse est le LIVRABLE, pas le projet : on réinterroge le détail
      // plutôt que de recomposer la fiche à la main — le décompte des remises
      // est calculé par le serveur.
      await queryClient.invalidateQueries({ queryKey: projectKeys.all });
      await refetch();
      setDeliverableTitle("");
      setDeliverableDate("");
      setActionError(null);
    },
    onError: fail,
  });

  const removeMutation = useMutation({
    mutationFn: (id: string) => deleteDeliverable(id),
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: projectKeys.all });
      await refetch();
      setActionError(null);
    },
    onError: fail,
  });

  const pending =
    progressMutation.isPending ||
    addMutation.isPending ||
    removeMutation.isPending;

  const canSubmitDeliverable =
    deliverableTitle.trim().length >= 2 && deliverableDate !== "";

  return (
    <Sheet
      open={projectId !== null}
      onOpenChange={(open) => {
        if (!open) setActionError(null);
        onOpenChange(open);
      }}
    >
      <SheetContent className="w-full gap-0 overflow-y-auto sm:max-w-xl">
        {isPending ? (
          <div className="space-y-3 p-6">
            <Skeleton className="h-6 w-48" />
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-40 w-full" />
          </div>
        ) : isError || !data ? (
          <div className="p-6">
            <ErrorState
              detail={isError ? toApiProblem(error).detail : undefined}
              onRetry={() => void refetch()}
            />
          </div>
        ) : (
          <>
            <SheetHeader>
              <div className="flex items-center gap-2">
                <SheetTitle className="font-heading">{data.title}</SheetTitle>
                <ProjectStatusBadge status={data.status} />
              </div>
              {/* Client ET service sur la même ligne : ce sont les deux
                  réponses à « de quoi parle cette fiche ». Le service manque
                  souvent — le classement est facultatif — et on l'omet alors
                  plutôt que d'afficher un tiret, qui ferait croire à une donnée
                  perdue là où il n'y a qu'un champ non rempli.

                  `serviceName` est nul dans DEUX cas indiscernables ici, et
                  c'est voulu : le projet non classé, et la prestation archivée
                  depuis (le catalogue est en soft delete). L'un comme l'autre
                  se corrigent au même endroit — le formulaire. */}
              <SheetDescription>
                {data.clientName ?? t("table.archivedClient")}
                {data.serviceName ? ` · ${data.serviceName}` : null}
              </SheetDescription>
            </SheetHeader>

            <div className="space-y-6 px-4 pb-6">
              {actionError && (
                <p className="text-destructive text-sm" role="alert">
                  {actionError}
                </p>
              )}

              {/* Le bandeau passe AVANT les actions et la description : c'est
                  la première chose à savoir sur une fiche ouverte à la hâte, et
                  son bouton mène droit au formulaire qui la complète.

                  `missingParts(data)` est recalculé à CHAQUE RENDU, sans état
                  local : le tiroir suit donc la requête de détail, que
                  l'enregistrement du formulaire invalide (`projectKeys.all`
                  couvre aussi la clé de détail). Un état figé à l'ouverture
                  aurait gardé le bandeau après la correction. */}
              {missingParts(data).length > 0 ? (
                <IncompleteProjectNotice
                  missing={missingParts(data)}
                  onComplete={() => onEdit(data)}
                />
              ) : null}

              <div className="flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onClick={() => onEdit(data)}>
                  {t("actions.edit")}
                </Button>
              </div>

              {data.description && (
                <p className="text-muted-foreground text-sm">{data.description}</p>
              )}

              {/* ------------------------------------------- Avancement */}
              <section className="ring-border space-y-3 rounded-lg px-3 py-3 ring-1">
                <h3 className="text-sm font-medium">{t("detail.progressTitle")}</h3>

                <ProgressBar
                  value={data.progressPercentage}
                  label={t("detail.progressLabel", { title: data.title })}
                />

                {isProgressEditable(data) ? (
                  <div className="space-y-3">
                    <div className="space-y-1.5">
                      <Label htmlFor="project-status">{t("form.status")}</Label>
                      <Select
                        value={draftStatus}
                        onValueChange={(value) =>
                          setDraftStatus(value as ProjectStatus)
                        }
                      >
                        <SelectTrigger id="project-status" className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {PROJECT_STATUSES.map((value) => (
                            <SelectItem key={value} value={value}>
                              {t(`status.${value}`)}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    <div className="space-y-1.5">
                      <Label htmlFor="project-progress">
                        {t("form.progress")}
                      </Label>
                      <div className="flex items-center gap-2">
                        <Input
                          id="project-progress"
                          type="number"
                          min={0}
                          max={100}
                          step={1}
                          value={draftProgress}
                          onChange={(event) =>
                            setDraftProgress(
                              // `Number.isNaN` sur un champ vidé : le laisser
                              // remonter poserait NaN dans le payload, que le
                              // serveur rejetterait sans que l'écran sache dire
                              // pourquoi.
                              Number.isNaN(event.target.valueAsNumber)
                                ? 0
                                : event.target.valueAsNumber,
                            )
                          }
                          className="w-24"
                        />
                        <span className="text-muted-foreground text-sm">%</span>
                      </div>
                    </div>

                    <Button
                      size="sm"
                      loading={progressMutation.isPending}
                      disabled={pending}
                      onClick={() =>
                        progressMutation.mutate({
                          id: data.id,
                          status: draftStatus,
                          progressPercentage: draftProgress,
                        })
                      }
                    >
                      {progressMutation.isPending
                        ? tCommon("saving")
                        : t("actions.saveProgress")}
                    </Button>
                  </div>
                ) : (
                  // Projet annulé : le serveur refuserait la mise à jour (409).
                  // On ne propose donc pas un geste voué à échouer.
                  <p className="text-muted-foreground text-sm">
                    {t("detail.canceledHint")}
                  </p>
                )}
              </section>

              {/* -------------------------------- Livrables transmis */}
              <section className="space-y-3">
                <h3 className="text-sm font-medium">
                  {t("detail.deliverablesTitle")}
                </h3>

                {(data.deliverables ?? []).length === 0 ? (
                  <p className="text-muted-foreground text-sm">
                    {t("detail.noDeliverables")}
                  </p>
                ) : (
                  <ul className="ring-border divide-border divide-y overflow-hidden rounded-lg ring-1">
                    {(data.deliverables ?? []).map((deliverable) => (
                      <li
                        key={deliverable.id}
                        className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                      >
                        <div className="min-w-0">
                          <p className="truncate font-medium">
                            {deliverable.title}
                          </p>
                          <p className="text-muted-foreground text-xs">
                            {formatDate(deliverable.deliveryDate, locale)}
                          </p>
                        </div>
                        <Button
                          size="icon"
                          variant="ghost"
                          // `variables` cible LA ligne cliquée : sans cette
                          // comparaison, retirer un livrable ferait tourner le
                          // spinner sur tous les autres en même temps.
                          loading={
                            removeMutation.isPending &&
                            removeMutation.variables === deliverable.id
                          }
                          disabled={pending}
                          aria-label={t("actions.removeDeliverable")}
                          onClick={() => removeMutation.mutate(deliverable.id)}
                        >
                          <Trash2 className="size-4" aria-hidden />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}

                {/* Titre + date, les deux seuls champs imposés : une remise se
                    note en passant, et un formulaire plus long ferait renoncer
                    à la noter. Les notes se saisissent depuis la fiche. */}
                <div className="ring-border space-y-3 rounded-lg px-3 py-3 ring-1">
                  <div className="space-y-1.5">
                    <Label htmlFor="deliverable-title">
                      {t("form.deliverableTitle")}
                    </Label>
                    <Input
                      id="deliverable-title"
                      value={deliverableTitle}
                      onChange={(event) => setDeliverableTitle(event.target.value)}
                      placeholder={t("form.deliverablePlaceholder")}
                    />
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="deliverable-date">
                      {t("form.deliveryDate")}
                    </Label>
                    <Input
                      id="deliverable-date"
                      type="date"
                      value={deliverableDate}
                      onChange={(event) => setDeliverableDate(event.target.value)}
                    />
                  </div>

                  <Button
                    size="sm"
                    variant="outline"
                    loading={addMutation.isPending}
                    disabled={pending || !canSubmitDeliverable}
                    onClick={() =>
                      addMutation.mutate({
                        id: data.id,
                        title: deliverableTitle,
                        deliveryDate: deliverableDate,
                      })
                    }
                  >
                    <Plus className="size-4" aria-hidden />
                    {t("actions.addDeliverable")}
                  </Button>
                </div>
              </section>
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
