"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { useEffect, useRef } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";

import { Button } from "@/components/ui/button";
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
import { useClientProjects } from "@/features/projects/hooks/use-client-projects";
import type { Document } from "@/features/documents/schemas/document";
import {
  createSituation,
  situationKeys,
  updateSituation,
} from "@/features/situations/api/situations";
import {
  emptySituation,
  situationFormSchema,
  SITUATION_STATUSES,
  toFormValues,
  type SituationFormValues,
  type SituationStatus,
} from "@/features/situations/schemas/situation";
import { useRouter } from "@/lib/i18n/navigation";
import { cn } from "@/lib/utils";

/**
 * Pastilles du sélecteur, alignées sur les teintes du badge de liste : « non
 * payé » emprunte le rouge de `overdue` (c'est la préoccupation de l'écran),
 * « en cours » le bleu de `sent` (une affaire qui suit son cours).
 */
const STATUS_DOT: Record<SituationStatus, string> = {
  sent: "bg-status-overdue",
  in_progress: "bg-status-sent",
  partial: "bg-status-partial",
  paid: "bg-status-paid",
};

/**
 * Champs que le serveur nomme à l'identique, et qu'une erreur RFC 9457 peut
 * donc rattacher directement au champ fautif. Les montants en sont absents :
 * le serveur parle de `totalCents` et `paidCents` là où le formulaire saisit
 * des MAD — ils sont remappés à la main plus bas.
 */
const SERVER_FIELDS = ["partnerId", "projectId", "subject", "issuedAt", "status", "notes"] as const;

/** Valeur d'item pour « aucun projet » : Radix interdit la chaîne vide. */
const NO_PROJECT = "__none__";

/** Une heure : la liste des clients ne change pas en cours de saisie. */
const REFERENCE_STALE_TIME = 60 * 60 * 1000;

/**
 * Création et correction d'une situation.
 *
 * Formulaire volontairement NU : un client, une date, un objet, un montant, une
 * avance. Pas de lignes, pas de TVA, pas de choix de statut — celui-ci est
 * déduit de l'avance par le serveur, et l'offrir au choix permettrait
 * d'afficher « payé » sur une situation qui ne l'est pas.
 *
 * Une page plutôt qu'une boîte de dialogue, contrairement aux factures : la
 * saisie est courte mais les écrans « Ajouter » et « Éditer » ont leur propre
 * URL (demandées telles quelles), et une modale sans route ne s'ouvre pas au
 * rechargement.
 */
export function SituationForm({ situation }: { situation?: Document }) {
  const t = useTranslations("situations");
  const tCommon = useTranslations("common");
  // Racine et non "validation" : les messages du schéma Zod sont des clés
  // ABSOLUES (« validation.required »), pour rester lisibles à la lecture du
  // schéma sans avoir à deviner le namespace qui les résoudra.
  const tRoot = useTranslations();
  const router = useRouter();
  const queryClient = useQueryClient();

  const { data: partners, isPending: partnersPending } = useQuery({
    queryKey: partnerKeys.list({ type: "client" }),
    queryFn: () => fetchPartners({ type: "client" }),
    staleTime: REFERENCE_STALE_TIME,
  });

  const {
    control,
    handleSubmit,
    register,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<SituationFormValues>({
    resolver: zodResolver(situationFormSchema),
    defaultValues: situation ? toFormValues(situation) : emptySituation(),
  });

  const partnerId = useWatch({ control, name: "partnerId" });
  const { projects, isPending: projectsPending } = useClientProjects(partnerId);

  // CHANGER DE CLIENT VIDE LE PROJET : garder le chantier du client précédent
  // ferait répondre 422 au serveur au moment d'enregistrer, après toute la
  // saisie. La première valeur observée est ignorée — en modification, elle
  // vient du document et effacerait le rattachement qu'on vient de charger.
  const previousPartnerId = useRef<string | null>(null);

  useEffect(() => {
    if (previousPartnerId.current !== null && previousPartnerId.current !== partnerId) {
      setValue("projectId", "");
    }

    previousPartnerId.current = partnerId;
  }, [partnerId, setValue]);

  const mutation = useMutation({
    mutationFn: (values: SituationFormValues) =>
      situation ? updateSituation(situation.id, values) : createSituation(values),
    onSuccess: () => {
      // Les totaux des indicateurs changent aussi : on invalide toute la
      // famille plutôt que de patcher chaque cache filtré à la main.
      void queryClient.invalidateQueries({ queryKey: situationKeys.all });
      router.push("/situations");
    },
    onError: (error) => {
      const problem = applyProblemToForm(error, setError, SERVER_FIELDS);

      // Le serveur valide des centimes, le formulaire saisit des MAD : sans ce
      // remappage, « l'avance dépasse le montant » n'atterrirait sur aucun
      // champ et finirait dans l'erreur générale.
      const paidError = problem.errors?.paidCents?.[0];
      if (paidError !== undefined) {
        setError("paid", { type: "server", message: paidError });
      }

      const totalError = problem.errors?.totalCents?.[0];
      if (totalError !== undefined) {
        setError("total", { type: "server", message: totalError });
      }
    },
  });

  /** Les messages Zod sont des CLÉS i18n ; le serveur renvoie du texte déjà traduit. */
  const message = (key: string | undefined): string | undefined => {
    if (key === undefined) return undefined;

    return key.startsWith("validation.") ? tRoot(key) : key;
  };

  return (
    <form
      onSubmit={handleSubmit((values) => mutation.mutate(values))}
      className="max-w-2xl"
      noValidate
    >
      <FieldGroup>
        <Field data-invalid={errors.partnerId ? true : undefined}>
          <FieldLabel htmlFor="partnerId">{t("form.client")}</FieldLabel>
          <Controller
            control={control}
            name="partnerId"
            render={({ field }) => (
              <Select
                value={field.value}
                onValueChange={field.onChange}
                disabled={partnersPending}
              >
                <SelectTrigger id="partnerId" aria-invalid={errors.partnerId ? true : undefined}>
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
          <FieldError errors={[{ message: message(errors.partnerId?.message) }]} />
        </Field>

        {/* PROJET, affiché seulement une fois le client choisi : les projets
            lui appartiennent, il n'y a rien à proposer avant. */}
        {partnerId !== "" && (
          <Field data-invalid={errors.projectId ? true : undefined}>
            <FieldLabel htmlFor="projectId">{t("form.project")}</FieldLabel>
            <Controller
              control={control}
              name="projectId"
              render={({ field }) => (
                <Select
                  value={field.value === "" ? NO_PROJECT : field.value}
                  onValueChange={(value) =>
                    field.onChange(value === NO_PROJECT ? "" : value)
                  }
                  disabled={projectsPending}
                >
                  <SelectTrigger id="projectId">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_PROJECT}>{t("form.noProject")}</SelectItem>
                    {projects.map((project) => (
                      <SelectItem key={project.id} value={project.id}>
                        {project.title}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            />
            <FieldError errors={[{ message: message(errors.projectId?.message) }]} />
          </Field>
        )}

        <Field data-invalid={errors.issuedAt ? true : undefined}>
          <FieldLabel htmlFor="issuedAt">{t("form.date")}</FieldLabel>
          <Input id="issuedAt" type="date" {...register("issuedAt")} />
          <FieldError errors={[{ message: message(errors.issuedAt?.message) }]} />
        </Field>

        <Field data-invalid={errors.status ? true : undefined}>
          <FieldLabel htmlFor="status">{t("form.status")}</FieldLabel>
          <Controller
            control={control}
            name="status"
            render={({ field }) => (
              <Select value={field.value} onValueChange={field.onChange}>
                <SelectTrigger id="status" aria-invalid={errors.status ? true : undefined}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {SITUATION_STATUSES.map((value) => (
                    <SelectItem key={value} value={value}>
                      {/* La pastille reprend la couleur du badge de la liste :
                          le choix se fait sur le même repère visuel que celui
                          qu'on lira ensuite dans le tableau. */}
                      <span className="flex items-center gap-2">
                        <span
                          aria-hidden
                          className={cn("size-1.5 rounded-full", STATUS_DOT[value])}
                        />
                        {t(`status.${value}`)}
                      </span>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            )}
          />
          <FieldError errors={[{ message: message(errors.status?.message) }]} />
        </Field>

        <Field data-invalid={errors.subject ? true : undefined}>
          <FieldLabel htmlFor="subject">{t("form.subject")}</FieldLabel>
          <Input
            id="subject"
            {...register("subject")}
            placeholder={t("form.subjectPlaceholder")}
            aria-invalid={errors.subject ? true : undefined}
          />
          <FieldError errors={[{ message: message(errors.subject?.message) }]} />
        </Field>

        <div className="grid gap-4 sm:grid-cols-2">
          <Field data-invalid={errors.total ? true : undefined}>
            <FieldLabel htmlFor="total">{t("form.total")}</FieldLabel>
            <Input
              id="total"
              type="number"
              step="0.01"
              min="0"
              // `valueAsNumber` : sans lui, RHF remonterait une chaîne et le
              // schéma Zod la rejetterait comme « montant invalide ».
              {...register("total", { valueAsNumber: true })}
              aria-invalid={errors.total ? true : undefined}
            />
            <FieldError errors={[{ message: message(errors.total?.message) }]} />
          </Field>

          <Field data-invalid={errors.paid ? true : undefined}>
            <FieldLabel htmlFor="paid">{t("form.advance")}</FieldLabel>
            <Input
              id="paid"
              type="number"
              step="0.01"
              min="0"
              {...register("paid", { valueAsNumber: true })}
              aria-invalid={errors.paid ? true : undefined}
            />
            <FieldError errors={[{ message: message(errors.paid?.message) }]} />
          </Field>
        </div>

        <Field>
          <FieldLabel htmlFor="notes">{t("form.notes")}</FieldLabel>
          <Textarea id="notes" rows={3} {...register("notes")} />
        </Field>

        {errors.root?.server ? (
          <p role="alert" className="text-destructive text-sm">
            {errors.root.server.message}
          </p>
        ) : null}

        <div className="flex items-center gap-2">
          <Button type="submit" loading={isSubmitting || mutation.isPending}>
            {mutation.isPending ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : null}
            {situation ? tCommon("save") : tCommon("create")}
          </Button>
          <Button type="button" variant="ghost" onClick={() => router.push("/situations")}>
            {tCommon("cancel")}
          </Button>
        </div>
      </FieldGroup>
    </form>
  );
}
