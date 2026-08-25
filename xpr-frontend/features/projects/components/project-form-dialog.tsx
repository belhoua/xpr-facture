"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useTranslations } from "next-intl";
import { useEffect, useMemo } from "react";
import { Controller, useForm } from "react-hook-form";

import { Button } from "@/components/ui/button";
import { Combobox } from "@/components/ui/combobox";
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
import { REFERENCE_STALE_TIME } from "@/lib/api/stale-times";
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
  createProjectService,
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
   * Prestations du catalogue. Chargées à l'ouverture seulement : c'est une
   * liste courte et stable, inutile de la redemander à chaque frappe du
   * formulaire. La clé vivant sous `catalogKeys`, une prestation créée depuis
   * `/services` invalide celle-ci au passage et le déroulant est déjà à jour.
   */
  const { data: services } = useQuery({
    queryKey: projectServiceKeys.list(),
    queryFn: fetchProjectServices,
    enabled: open,
    // Même fraîcheur que les autres référentiels (TVA, catégories) : la liste
    // est courte et quasi figée, et ce dialogue s'ouvre et se referme dix fois
    // par heure. Sans ce délai, chaque ouverture repayait une requête pour
    // ramener la même réponse.
    staleTime: REFERENCE_STALE_TIME,
  });

  /** Options du sélecteur de service, dans l'ordre alphabétique du serveur. */
  const serviceOptions = useMemo(
    () => (services ?? []).map((service) => ({
      value: service.id,
      label: service.name,
    })),
    [services],
  );

  /**
   * Création d'une prestation depuis le champ.
   *
   * Elle est SÉLECTIONNÉE d'office : on n'ouvre pas ce champ pour laisser le
   * projet non classé, et redemander un clic après avoir tapé le nom serait un
   * geste de plus pour rien.
   *
   * Le catalogue est invalidé plutôt que patché à la main — et invalidé EN
   * ENTIER (`catalogKeys.products()`), ce qui rafraîchit du même coup l'écran
   * `/services` : les deux listent désormais la même chose, et n'en rafraîchir
   * qu'une ferait diverger l'affichage de deux onglets ouverts.
   */
  const createService = useMutation({
    mutationFn: (name: string) => createProjectService(name),
    onSuccess: async (service) => {
      await queryClient.invalidateQueries({ queryKey: projectServiceKeys.all });
      form.setValue("serviceId", service.id, { shouldDirty: true });
    },
    // Sans ce traitement, un refus passerait inaperçu : le champ resterait vide
    // sans un mot. Deux cas réels — un nom de moins de deux caractères (422) et
    // un compte LECTEUR, qui consulte le référentiel sans pouvoir l'alimenter
    // (403). L'erreur se pose sur le champ visé.
    onError: (error) =>
      applyProblemToForm(error, form.setError, ["serviceId"]),
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

            {/* SERVICE, facultatif : la prestation dont relève le projet.

                La liste est celle du CATALOGUE — exactement ce que l'écran
                `/services` gère. Elle visait auparavant un second référentiel
                que ce déroulant seul alimentait, si bien qu'une prestation
                créée dans `/services` n'y apparaissait jamais ; les deux ont
                été fusionnés le 2026-08-26 (cf.
                `features/projects/api/services.ts`).

                Créer depuis ce champ reste possible et crée un vrai article du
                catalogue, à 0 MAD : on n'ouvre pas ce déroulant pour laisser
                le projet non classé, et renvoyer vers un autre écran au milieu
                d'une saisie ferait perdre le formulaire en cours. Le prix se
                complète ensuite dans `/services`. */}
            <Field>
              <FieldLabel htmlFor="project-service">
                {t("form.service")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="serviceId"
                render={({ field }) => (
                  <Combobox
                    id="project-service"
                    options={serviceOptions}
                    value={field.value}
                    onChange={field.onChange}
                    placeholder={t("form.noService")}
                    searchPlaceholder={t("form.searchService")}
                    emptyLabel={t("form.noServiceYet")}
                    // Créer depuis la recherche, puis sélectionner d'office : on
                    // n'ouvre pas ce champ pour laisser le service de côté.
                    onCreate={(name) => createService.mutate(name)}
                    createLabel={(name) => t("form.createService", { name })}
                    disabled={createService.isPending}
                  />
                )}
              />
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
          <Button type="submit" form="project-form" loading={mutation.isPending}>
            {mutation.isPending ? t("form.saving") : t("form.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
