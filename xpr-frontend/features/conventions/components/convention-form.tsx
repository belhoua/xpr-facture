"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Loader2 } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
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
import {
  conventionKeys,
  createConvention,
  updateConvention,
} from "@/features/conventions/api/conventions";
import {
  CONVENTION_STATUSES,
  conventionFormSchema,
  emptyConvention,
  toConventionFormValues,
  type Convention,
  type ConventionFormValues,
} from "@/features/conventions/schemas/convention";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import { formatAmount } from "@/lib/format";
import { useRouter } from "@/lib/i18n/navigation";

/** Une heure : la liste des clients ne change pas en cours de saisie. */
const REFERENCE_STALE_TIME = 60 * 60 * 1000;

/**
 * Champs que le serveur nomme à l'identique, et qu'une erreur RFC 9457 peut donc
 * rattacher directement au champ fautif. `totalCents` en est absent : le serveur
 * parle de centimes là où le formulaire saisit des MAD — il est remappé à la
 * main plus bas.
 */
const SERVER_FIELDS = [
  "partnerId",
  "ownerName",
  "ownerIce",
  "ownerRc",
  "ownerAddress",
  "projectDescription",
  "projectAddress",
  "projectTitleDeed",
  "dossierNumber",
  "status",
  "issueCity",
  "issuedAt",
  "lots",
  "executionDelay",
  "advancePercent",
  "visaPercent",
  "completionPercent",
  "notes",
] as const;

/**
 * Rédaction et correction d'un contrat de convention.
 *
 * Une PAGE et non une boîte de dialogue : le formulaire couvre six sections et
 * l'écran a sa propre URL, donc il survit à un rechargement et se partage par
 * lien — ce qu'une modale sans route ne permet pas. Même choix que les
 * situations, pour la même raison.
 *
 * Le formulaire n'est jamais vide en pratique : il s'ouvre le plus souvent sur
 * une convention issue d'un devis, où l'identité et les honoraires sont déjà
 * remplis et où il reste le titre foncier et les lots à ajuster.
 */
export function ConventionForm({ convention }: { convention?: Convention }) {
  const t = useTranslations("conventions");
  const tCommon = useTranslations("common");
  // Racine et non "validation" : les messages du schéma Zod sont des clés
  // ABSOLUES (« validation.required »), pour rester lisibles à la lecture du
  // schéma sans avoir à deviner le namespace qui les résoudra.
  const tRoot = useTranslations();
  const locale = useLocale();
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
    getValues,
    formState: { errors, isSubmitting },
  } = useForm<ConventionFormValues>({
    resolver: zodResolver(conventionFormSchema),
    defaultValues: convention
      ? toConventionFormValues(convention)
      : emptyConvention(),
  });

  const mutation = useMutation({
    mutationFn: (values: ConventionFormValues) =>
      convention
        ? updateConvention(convention.id, values)
        : createConvention(values),
    onSuccess: async (saved) => {
      queryClient.setQueryData(conventionKeys.detail(saved.id), saved);
      await queryClient.invalidateQueries({ queryKey: conventionKeys.all });
      router.push("/conventions");
    },
    onError: (error) => {
      const problem = applyProblemToForm(error, setError, SERVER_FIELDS);

      // Le serveur valide des centimes, le formulaire saisit des MAD : sans ce
      // remappage, « montant hors bornes » n'atterrirait sur aucun champ et
      // finirait dans l'erreur générale.
      const totalError = problem.errors?.totalCents?.[0];
      if (totalError !== undefined) {
        setError("total", { type: "server", message: totalError });
      }
    },
  });

  /** Les messages Zod sont des CLÉS i18n ; le serveur renvoie du texte traduit. */
  const message = (key: string | undefined): string | undefined => {
    if (key === undefined) return undefined;

    return key.startsWith("validation.") ? tRoot(key) : key;
  };

  /**
   * Choisir un tiers RECOPIE son identité légale dans les champs du contrat.
   *
   * Une recopie et non une liaison : la convention fige l'identité du maître
   * d'ouvrage au jour de la signature, exactement comme un document commercial
   * fige celle du client (§3). Le contrat imprimé ne doit pas se mettre à
   * afficher une nouvelle raison sociale parce que la fiche a été renommée
   * après coup.
   *
   * Les champs DÉJÀ SAISIS ne sont pas écrasés : on rattache souvent un tiers
   * après avoir tapé l'identité à la main, et perdre cette saisie serait pire
   * que de ne rien pré-remplir.
   */
  const applyPartner = (partnerId: string) => {
    const partner = partners?.data.find((candidate) => candidate.id === partnerId);

    if (partner === undefined) {
      return;
    }

    const fill = (
      field: "ownerName" | "ownerIce" | "ownerRc" | "ownerAddress",
      value: string | null,
    ) => {
      if (value !== null && getValues(field).trim() === "") {
        setValue(field, value, { shouldValidate: true });
      }
    };

    fill("ownerName", partner.legalName);
    fill("ownerIce", partner.ice);
    fill("ownerRc", partner.rcNumber);
    fill("ownerAddress", partner.address);
  };

  // Aperçu de l'échéancier, RECALCULÉ À LA SAISIE. C'est un aperçu et rien de
  // plus : le contrat imprimé affiche `instalmentsCents`, calculé par le
  // serveur, qui fait autorité sur l'arrondi (cf. `Convention::instalments()`).
  const total = useWatch({ control, name: "total" });
  const advance = useWatch({ control, name: "advancePercent" });
  const visa = useWatch({ control, name: "visaPercent" });
  const totalCents = Math.round((Number.isFinite(total) ? total : 0) * 100);
  const advanceCents = Math.trunc((totalCents * (advance || 0)) / 100);
  const visaCents = Math.trunc((totalCents * (visa || 0)) / 100);

  return (
    <form
      onSubmit={handleSubmit((values) => mutation.mutate(values))}
      className="max-w-3xl"
      noValidate
    >
      <FieldGroup>
        <Section title={t("form.sections.owner")}>
          <Field>
            <FieldLabel htmlFor="partnerId">{t("form.partner")}</FieldLabel>
            <Controller
              control={control}
              name="partnerId"
              render={({ field }) => (
                <Select
                  value={field.value}
                  onValueChange={(value) => {
                    field.onChange(value);
                    applyPartner(value);
                  }}
                  disabled={partnersPending}
                >
                  <SelectTrigger id="partnerId">
                    <SelectValue placeholder={t("form.partnerPlaceholder")} />
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
          </Field>

          <Field data-invalid={errors.ownerName ? true : undefined}>
            <FieldLabel htmlFor="ownerName">{t("form.ownerName")}</FieldLabel>
            <Input
              id="ownerName"
              {...register("ownerName")}
              aria-invalid={errors.ownerName ? true : undefined}
            />
            <FieldError errors={[{ message: message(errors.ownerName?.message) }]} />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field data-invalid={errors.ownerIce ? true : undefined}>
              <FieldLabel htmlFor="ownerIce">{t("form.ownerIce")}</FieldLabel>
              <Input
                id="ownerIce"
                inputMode="numeric"
                {...register("ownerIce")}
                aria-invalid={errors.ownerIce ? true : undefined}
              />
              <FieldError errors={[{ message: message(errors.ownerIce?.message) }]} />
            </Field>

            <Field data-invalid={errors.ownerRc ? true : undefined}>
              <FieldLabel htmlFor="ownerRc">{t("form.ownerRc")}</FieldLabel>
              <Input id="ownerRc" {...register("ownerRc")} />
              <FieldError errors={[{ message: message(errors.ownerRc?.message) }]} />
            </Field>
          </div>

          <Field>
            <FieldLabel htmlFor="ownerAddress">{t("form.ownerAddress")}</FieldLabel>
            <Textarea id="ownerAddress" rows={2} {...register("ownerAddress")} />
          </Field>
        </Section>

        <Section title={t("form.sections.project")}>
          <Field data-invalid={errors.projectDescription ? true : undefined}>
            <FieldLabel htmlFor="projectDescription">
              {t("form.projectDescription")}
            </FieldLabel>
            <Textarea
              id="projectDescription"
              rows={3}
              placeholder={t("form.projectDescriptionPlaceholder")}
              {...register("projectDescription")}
              aria-invalid={errors.projectDescription ? true : undefined}
            />
            <FieldError
              errors={[{ message: message(errors.projectDescription?.message) }]}
            />
          </Field>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="projectAddress">
                {t("form.projectAddress")}
              </FieldLabel>
              <Input id="projectAddress" {...register("projectAddress")} />
            </Field>

            <Field>
              <FieldLabel htmlFor="projectTitleDeed">
                {t("form.projectTitleDeed")}
              </FieldLabel>
              <Input
                id="projectTitleDeed"
                placeholder="138618/04"
                {...register("projectTitleDeed")}
              />
            </Field>
          </div>
        </Section>

        <Section title={t("form.sections.mission")}>
          <Field>
            <FieldLabel htmlFor="lots">{t("form.lots")}</FieldLabel>
            {/* Un lot par ligne : c'est ainsi qu'on les rédige, et c'est ainsi
                qu'on les colle depuis un précédent contrat. */}
            <Textarea
              id="lots"
              rows={5}
              placeholder={t("form.lotsPlaceholder")}
              {...register("lots")}
            />
            <p className="text-muted-foreground text-xs">{t("form.lotsHint")}</p>
          </Field>

          <Field>
            <FieldLabel htmlFor="executionDelay">
              {t("form.executionDelay")}
            </FieldLabel>
            <Input
              id="executionDelay"
              placeholder={t("form.executionDelayPlaceholder")}
              {...register("executionDelay")}
            />
          </Field>
        </Section>

        <Section title={t("form.sections.fees")}>
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

          <div className="grid gap-4 sm:grid-cols-3">
            <PercentField
              id="advancePercent"
              label={t("form.advancePercent")}
              amount={formatAmount(advanceCents, locale)}
              register={register("advancePercent", { valueAsNumber: true })}
              invalid={errors.advancePercent !== undefined}
            />
            <PercentField
              id="visaPercent"
              label={t("form.visaPercent")}
              amount={formatAmount(visaCents, locale)}
              register={register("visaPercent", { valueAsNumber: true })}
              invalid={errors.visaPercent !== undefined}
            />
            <PercentField
              id="completionPercent"
              label={t("form.completionPercent")}
              // Le SOLDE absorbe l'arrondi : trois quarts d'un montant impair
              // ne tombent pas juste, et la somme des trois doit faire le total.
              amount={formatAmount(totalCents - advanceCents - visaCents, locale)}
              register={register("completionPercent", { valueAsNumber: true })}
              invalid={errors.completionPercent !== undefined}
            />
          </div>

          {/* Une seule erreur pour les trois parts : la somme est une propriété
              de l'échéancier, pas d'un champ. Le schéma la rattache à l'avance
              pour qu'elle ait un point d'ancrage visible. */}
          <FieldError
            errors={[{ message: message(errors.advancePercent?.message) }]}
          />
        </Section>

        <Section title={t("form.sections.file")}>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field data-invalid={errors.dossierNumber ? true : undefined}>
              <FieldLabel htmlFor="dossierNumber">
                {t("form.dossierNumber")}
              </FieldLabel>
              <Input
                id="dossierNumber"
                placeholder="0003439/AK/26"
                {...register("dossierNumber")}
              />
              <p className="text-muted-foreground text-xs">
                {t("form.dossierNumberHint")}
              </p>
              <FieldError
                errors={[{ message: message(errors.dossierNumber?.message) }]}
              />
            </Field>

            <Field data-invalid={errors.status ? true : undefined}>
              <FieldLabel htmlFor="status">{t("form.status")}</FieldLabel>
              <Controller
                control={control}
                name="status"
                render={({ field }) => (
                  <Select value={field.value} onValueChange={field.onChange}>
                    <SelectTrigger id="status">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {CONVENTION_STATUSES.map((value) => (
                        <SelectItem key={value} value={value}>
                          {t(`status.${value}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              />
            </Field>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="issueCity">{t("form.issueCity")}</FieldLabel>
              <Input
                id="issueCity"
                placeholder={t("form.issueCityPlaceholder")}
                {...register("issueCity")}
              />
            </Field>

            <Field>
              <FieldLabel htmlFor="issuedAt">{t("form.issuedAt")}</FieldLabel>
              <Input id="issuedAt" type="date" {...register("issuedAt")} />
            </Field>
          </div>
        </Section>

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
          <Button type="submit" disabled={isSubmitting || mutation.isPending}>
            {mutation.isPending ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : null}
            {convention ? tCommon("save") : tCommon("create")}
          </Button>
          <Button
            type="button"
            variant="ghost"
            onClick={() => router.push("/conventions")}
          >
            {tCommon("cancel")}
          </Button>
        </div>
      </FieldGroup>
    </form>
  );
}

/**
 * Regroupement titré. Le formulaire couvre six domaines distincts — identité,
 * projet, mission, honoraires, dossier, notes — et une colonne de vingt champs
 * sans repère se relit mal, surtout à la vérification d'un contrat.
 */
function Section({
  title,
  children,
}: {
  title: string;
  children: React.ReactNode;
}) {
  return (
    <section className="space-y-4">
      <h2 className="text-muted-foreground border-b pb-1 text-xs font-semibold tracking-wide uppercase">
        {title}
      </h2>
      {children}
    </section>
  );
}

/**
 * Part de l'échéancier : un pourcentage saisi, le montant correspondant affiché
 * dessous. Sans ce montant, personne ne vérifie qu'une avance de 25 % fait bien
 * la somme qu'on attend — et c'est cette somme-là qui sera réclamée au client.
 */
function PercentField({
  id,
  label,
  amount,
  register,
  invalid,
}: {
  id: string;
  label: string;
  amount: string;
  register: ReturnType<ReturnType<typeof useForm<ConventionFormValues>>["register"]>;
  invalid: boolean;
}) {
  return (
    <Field data-invalid={invalid ? true : undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Input
        id={id}
        type="number"
        step="1"
        min="0"
        max="100"
        {...register}
        aria-invalid={invalid ? true : undefined}
      />
      <p className="text-muted-foreground amount text-xs">{amount}</p>
    </Field>
  );
}
