"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect } from "react";
import { Controller, useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Textarea } from "@/components/ui/textarea";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import {
  createProject,
  projectKeys,
  updateProject,
} from "@/features/projects/api/projects";
import {
  projectFormSchema,
  PROJECT_STATUSES,
  type Project,
  type ProjectFormValues,
} from "@/features/projects/schemas/project";
import {
  fetchProjectServices,
  projectServiceKeys,
} from "@/features/projects/api/services";

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = [
  "partnerId",
  "serviceId",
  "title",
  "status",
  "progressPercentage",
  "description",
] as const;

/** Valeur d'item pour « Aucun » : Radix interdit la chaîne vide. */
const NO_SERVICE = "__none__";

function emptyValues(): ProjectFormValues {
  return {
    partnerId: "",
    serviceId: "",
    title: "",
    // Un projet s'ouvre « en cours », à 0 % : c'est le défaut du serveur, repris
    // ici pour que le formulaire montre ce qui sera écrit.
    status: "in_progress",
    progressPercentage: 0,
    description: "",
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromProject(project: Project): ProjectFormValues {
  return {
    partnerId: project.partnerId,
    serviceId: project.serviceId ?? "",
    title: project.title,
    status: project.status,
    progressPercentage: project.progressPercentage,
    description: project.description ?? "",
  };
}

/**
 * Création / édition d'un projet. Un seul composant pour les deux modes.
 *
 * `target` vaut `"new"` pour une création, le projet lui-même pour une
 * correction, et `null` quand le dialogue est fermé — une seule variable là où
 * un `open` et un `project` séparés autoriseraient l'état incohérent « ouvert
 * en édition, sans projet ».
 */
export function ProjectFormDialog({
  target,
  onOpenChange,
}: {
  target: Project | "new" | null;
  onOpenChange: (open: boolean) => void;
}) {
  const t = useTranslations("projects");
  const tRoot = useTranslations();
  const queryClient = useQueryClient();

  const open = target !== null;
  const project = target === "new" ? null : target;
  const isEdit = project !== null;

  const form = useForm<ProjectFormValues>({
    resolver: zodResolver(projectFormSchema),
    defaultValues: emptyValues(),
  });

  useEffect(() => {
    if (open) {
      form.reset(project ? valuesFromProject(project) : emptyValues());
    }
  }, [open, project, form]);

  const partnerFilters = { type: "client" as const };
  const { data: partners } = useQuery({
    queryKey: partnerKeys.list(partnerFilters),
    queryFn: () => fetchPartners(partnerFilters),
  });

  /**
   * Référentiel des services. Chargé à l'ouverture seulement : c'est une liste
   * courte et stable, inutile de la redemander à chaque frappe du formulaire.
   */
  const { data: services } = useQuery({
    queryKey: projectServiceKeys.list(),
    queryFn: fetchProjectServices,
    enabled: open,
  });

  const mutation = useMutation({
    mutationFn: (values: ProjectFormValues) =>
      isEdit ? updateProject(project.id, values) : createProject(values),
    onSuccess: async (saved) => {
      queryClient.setQueryData(projectKeys.detail(saved.id), saved);
      await queryClient.invalidateQueries({ queryKey: projectKeys.all });
      onOpenChange(false);
    },
    onError: (error) => {
      // Le serveur répond 409 si le client n'appartient pas à la société : le
      // message atterrit alors dans `root.server`, faute de champ à viser.
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t("form.editTitle") : t("form.createTitle")}
          </DialogTitle>
          <DialogDescription>
            {isEdit ? t("form.editDescription") : t("form.createDescription")}
          </DialogDescription>
        </DialogHeader>

        <form
          id="project-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          className="max-h-[60vh] overflow-y-auto pe-1"
        >
          <FieldGroup>
            {errors.root?.server && (
              <p className="text-destructive text-sm" role="alert">
                {errors.root.server.message}
              </p>
            )}

            <Field>
              <FieldLabel htmlFor="project-client">{t("form.client")}</FieldLabel>
              <Controller
                control={form.control}
                name="partnerId"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="project-client" className="w-full">
                      <SelectValue placeholder={t("form.clientPlaceholder")} />
                    </SelectTrigger>
                    <SelectContent>
                      {(partners?.data ?? []).map((partner) => (
                        <SelectItem key={partner.id} value={partner.id}>
                          {partner.legalName}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              <FieldError>{fieldError(errors.partnerId?.message)}</FieldError>
            </Field>

            {/* SERVICE, facultatif : le libellé le dit, et « Aucun » ouvre la
                liste — deux façons de le signaler, parce qu'un champ vide
                sans mention laisse toujours croire qu'on a oublié de le
                remplir. Le référentiel naît vide : tant qu'aucun service n'est
                enregistré, seule l'entrée « Aucun » s'affiche, et la note en
                dessous dit pourquoi. */}
            <Field>
              <FieldLabel htmlFor="project-service">
                {t("form.service")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="serviceId"
                render={({ field }) => (
                  <Select
                    value={field.value === "" ? NO_SERVICE : field.value}
                    onValueChange={(value) =>
                      field.onChange(value === NO_SERVICE ? "" : value)
                    }
                  >
                    <SelectTrigger id="project-service" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={NO_SERVICE}>
                        {t("form.noService")}
                      </SelectItem>
                      {(services ?? []).map((service) => (
                        <SelectItem key={service.id} value={service.id}>
                          {service.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
              {(services ?? []).length === 0 && (
                <p className="text-muted-foreground text-xs">
                  {t("form.noServiceYet")}
                </p>
              )}
              <FieldError>{fieldError(errors.serviceId?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="project-title">{t("form.title")}</FieldLabel>
              <Input
                id="project-title"
                {...form.register("title")}
                placeholder={t("form.titlePlaceholder")}
              />
              <FieldError>{fieldError(errors.title?.message)}</FieldError>
            </Field>

            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="project-form-status">
                  {t("form.status")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="status"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="project-form-status" className="w-full">
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
                  )}
                />
                <FieldError>{fieldError(errors.status?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="project-form-progress">
                  {t("form.progress")}
                </FieldLabel>
                <Input
                  id="project-form-progress"
                  type="number"
                  min={0}
                  max={100}
                  step={1}
                  {...form.register("progressPercentage", { valueAsNumber: true })}
                />
                <FieldError>{fieldError(errors.progressPercentage?.message)}</FieldError>
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="project-description">
                {t("form.description")}
              </FieldLabel>
              <Textarea id="project-description" {...form.register("description")} />
              <FieldError>{fieldError(errors.description?.message)}</FieldError>
            </Field>
          </FieldGroup>
        </form>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t("form.cancel")}
          </Button>
          <Button type="submit" form="project-form" disabled={mutation.isPending}>
            {mutation.isPending ? t("form.saving") : t("form.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
