"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useLocale, useTranslations } from "next-intl";
import { useEffect, useMemo, useRef } from "react";
import { Controller, useForm, useWatch } from "react-hook-form";
import { toast } from "sonner";

import { ErrorState } from "@/components/patterns/error-state";
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
import { Field, FieldError, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import { applyProblemToForm } from "@/features/auth/hooks/use-auth";
import { catalogKeys, fetchProducts, fetchTaxRates } from "@/features/catalog/api/catalog";
import {
  createDocument,
  documentKeys,
  fetchDocument,
  updateDocument,
} from "@/features/documents/api/documents";
import { dashboardKeys } from "@/features/dashboard/api/dashboard";
import {
  DocumentLineEditor,
  emptyLine,
} from "@/features/documents/components/document-line-editor";
import {
  documentFormSchema,
  type Document,
  type DocumentFormValues,
  type DocumentType,
} from "@/features/documents/schemas/document";
import { computeTotals } from "@/features/documents/utils/totals";
import { fetchPartners, partnerKeys } from "@/features/partners/api/partners";
import { projectKeys } from "@/features/projects/api/projects";
import { useClientProjects } from "@/features/projects/hooks/use-client-projects";
import { toApiProblem } from "@/lib/api/client";
import { DEFAULT_CURRENCY, formatMoney } from "@/lib/format";
import { REFERENCE_STALE_TIME } from "@/lib/api/stale-times";

/** Champs mappables depuis une erreur de validation serveur (RFC 9457). */
const SERVER_FIELDS = [
  "partnerId",
  "projectId",
  "clientName",
  "subject",
  "issueCity",
  "issuedAt",
  "dueAt",
  "notes",
  "terms",
] as const;

/** Valeur d'item pour « aucun projet » : Radix interdit la chaîne vide. */
const NO_PROJECT = "__none__";


function todayIso(): string {
  return new Date().toISOString().slice(0, 10);
}

/**
 * Un document neuf s'ouvre avec UNE ligne prête à remplir : facturer en moins
 * de 60 secondes (§2) suppose de ne pas commencer par cliquer « ajouter ».
 */
function emptyValues(defaultTaxRateId: string): DocumentFormValues {
  return {
    partnerId: "",
    projectId: "",
    clientName: "",
    subject: "",
    issueCity: "",
    // Vide par défaut : la numérotation automatique reste le cas nominal, la
    // saisie manuelle l'exception que l'utilisateur pose sciemment.
    number: "",
    issuedAt: todayIso(),
    dueAt: "",
    notes: "",
    terms: "",
    items: [emptyLine(defaultTaxRateId)],
  };
}

/** L'API renvoie `null` pour un champ vide ; le formulaire manipule "". */
function valuesFromDocument(document: Document): DocumentFormValues {
  return {
    partnerId: document.partnerId ?? "",
    projectId: document.projectId ?? "",
    clientName: document.clientName,
    subject: document.subject ?? "",
    issueCity: document.issueCity ?? "",
    // Le numéro EXISTANT est pré-rempli : il est modifiable depuis le
    // 2026-08-18 sur les factures et les devis, et le champ doit donc partir
    // de ce que la pièce porte réellement, jamais d'un champ vide qui
    // laisserait croire qu'il n'y en a pas.
    number: document.number ?? "",
    issuedAt: document.issuedAt ?? "",
    dueAt: document.dueAt ?? "",
    notes: document.notes ?? "",
    terms: document.terms ?? "",
    items: (document.items ?? []).map((item) => ({
      productId: item.productId ?? "",
      label: item.label,
      description: item.description ?? "",
      quantity: Number(item.quantity),
      unit: item.unit ?? "",
      // Centimes → unités majeures : conversion d'AFFICHAGE, refaite en sens
      // inverse à l'envoi. Aucun calcul métier ne passe par ce flottant (§7).
      unitPrice: item.unitPriceCents / 100,
      discountPercent: Number(item.discountPercent),
      taxRateId: item.taxRateId ?? "",
    })),
  };
}

/**
 * Création / édition d'un document commercial (facture, devis).
 *
 * Un seul composant pour les trois : ils partagent la même structure, seul le
 * `type` change — et il n'est transmis qu'à la CRÉATION, puisque muter un devis
 * en facture contournerait la numérotation.
 *
 * L'édition n'est offerte que sur un BROUILLON (§3) ; c'est la liste qui filtre
 * l'action, et le serveur qui refuse en 409 si l'on force le passage.
 */
export function DocumentFormDialog({
  open,
  onOpenChange,
  type,
  document,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  type: DocumentType;
  document?: Document | null;
}) {
  const t = useTranslations("documents");
  const tRoot = useTranslations();
  const locale = useLocale();
  const queryClient = useQueryClient();
  const isEdit = Boolean(document);

  /**
   * La pièce peut-elle être renumérotée ?
   *
   * Miroir de `DocumentType::allowsNumberEdit()` : facture et devis seulement,
   * et une pièce DÉJÀ numérotée — sur un brouillon, le numéro reste l'affaire
   * de l'émission. Le serveur reste juge et répond 422 ; ce test évite
   * seulement d'afficher un champ dont la saisie serait rejetée.
   */
  const canEditNumber =
    document != null &&
    document.number !== null &&
    (document.type === "invoice" || document.type === "quote");

  const form = useForm<DocumentFormValues>({
    resolver: zodResolver(documentFormSchema(isEdit ? "edit" : "create")),
    defaultValues: emptyValues(""),
  });

  const partnersQuery = useQuery({
    queryKey: partnerKeys.list({ type: "client", search: "" }),
    queryFn: () => fetchPartners({ type: "client", search: "" }),
    enabled: open,
  });

  const productsQuery = useQuery({
    queryKey: catalogKeys.productList({
      search: "",
      type: "all",
      categoryId: "all",
    }),
    queryFn: () =>
      fetchProducts({ search: "", type: "all", categoryId: "all" }),
    enabled: open,
    staleTime: REFERENCE_STALE_TIME,
  });

  const taxRatesQuery = useQuery({
    queryKey: catalogKeys.taxRates(),
    queryFn: fetchTaxRates,
    enabled: open,
    staleTime: REFERENCE_STALE_TIME,
  });

  /**
   * Détail complet du document en cours de modification.
   *
   * Indispensable : l'appelant transmet souvent la ligne de LISTE, et
   * `fetchDocuments` n'expose pas `items` — ce serait une jointure pour rien sur
   * chaque page. Se contenter de cet objet ouvrirait le formulaire sans aucune
   * ligne, et l'enregistrement les effacerait toutes, le PATCH transmettant
   * alors un tableau vide.
   *
   * Le cache absorbe le coût : quand l'édition part du panneau de détail, la
   * réponse est déjà là et la requête ne repart pas.
   */
  const detailQuery = useQuery({
    queryKey: documentKeys.detail(document?.id ?? ""),
    queryFn: () => fetchDocument(document?.id ?? ""),
    enabled: open && document != null,
  });

  /** Ce qui pré-remplit réellement le formulaire, lignes comprises. */
  const editing = detailQuery.data ?? null;

  const taxRates = useMemo(() => taxRatesQuery.data ?? [], [taxRatesQuery.data]);
  const products = productsQuery.data?.data ?? [];

  /** Taux proposé sur une ligne neuve : celui marqué par défaut au référentiel. */
  const defaultTaxRateId = useMemo(
    () => taxRates.find((rate) => rate.isDefault)?.id ?? "",
    [taxRates],
  );

  // `defaultTaxRateId` fait partie des dépendances : si le référentiel arrive
  // après l'ouverture (cache froid), le formulaire vierge est réinitialisé
  // pour bénéficier du taux par défaut. La liste précharge ce référentiel, si
  // bien qu'en pratique il est déjà là — et le cas résiduel se produit dans la
  // seconde qui suit l'ouverture, avant toute saisie.
  //
  // En MODIFICATION, on attend le détail : réinitialiser d'abord sans les
  // lignes puis une seconde fois avec écraserait ce que l'utilisateur aurait
  // commencé à saisir entre les deux. Le formulaire reste masqué d'ici là.
  useEffect(() => {
    if (!open) {
      return;
    }

    if (document == null) {
      form.reset(emptyValues(defaultTaxRateId));

      return;
    }

    if (editing !== null) {
      form.reset(valuesFromDocument(editing));
    }
  }, [open, document, editing, defaultTaxRateId, form]);

  const partnerId = useWatch({ control: form.control, name: "partnerId" });

  /** Projets du client choisi — le déroulant n'en propose aucun autre. */
  const { projects, isPending: projectsPending } = useClientProjects(partnerId);

  // CHANGER DE CLIENT VIDE LE PROJET. Sans cela, un formulaire ouvert sur le
  // client A puis basculé sur B garderait le chantier de A, et le serveur
  // répondrait 422 au moment d'enregistrer — après la saisie des lignes, donc
  // au pire moment. La comparaison porte sur le client PRÉCÉDENT et non sur un
  // simple changement de `partnerId` : à l'ouverture en modification, la
  // valeur passe de "" au client du document, ce qui effacerait le
  // rattachement qu'on vient justement de charger.
  const previousPartnerId = useRef<string | null>(null);

  useEffect(() => {
    if (!open) {
      previousPartnerId.current = null;

      return;
    }

    if (previousPartnerId.current !== null && previousPartnerId.current !== partnerId) {
      form.setValue("projectId", "");
    }

    previousPartnerId.current = partnerId;
  }, [open, partnerId, form]);

  // Pas de `?? []` ici : la valeur de repli créerait un tableau neuf à chaque
  // rendu et invaliderait le mémo ci-dessous en permanence.
  const items = useWatch({ control: form.control, name: "items" });

  /** Aperçu du pied de document, recalculé à chaque frappe (cf. utils/totals). */
  const totals = useMemo(
    () =>
      computeTotals(
        (items ?? []).map((item) => ({
          quantity: Number(item.quantity ?? 0),
          unitPriceCents: Math.round(Number(item.unitPrice ?? 0) * 100),
          discountPercent: Number(item.discountPercent ?? 0),
          taxRatePercent: Number(
            taxRates.find((rate) => rate.id === item.taxRateId)?.rate ?? 0,
          ),
        })),
      ),
    [items, taxRates],
  );

  /**
   * Options du sélecteur de tiers.
   *
   * L'ICE accompagne la raison sociale : deux fiches d'un même groupe portent
   * souvent des noms très proches, et c'est l'identifiant qui les départage —
   * il entre d'ailleurs dans le champ de recherche du composant.
   */
  const partnerOptions = useMemo(
    () =>
      (partnersQuery.data?.data ?? []).map((partner) => ({
        value: partner.id,
        label: partner.displayName,
        hint: partner.ice ?? undefined,
      })),
    [partnersQuery.data],
  );

  const mutation = useMutation({
    mutationFn: (values: DocumentFormValues) =>
      isEdit && document
        ? updateDocument(document.id, values)
        : createDocument(type, values),
    onSuccess: async (saved) => {
      await queryClient.invalidateQueries({ queryKey: documentKeys.all });
      // Le chiffre d'affaires du tableau de bord vient de bouger.
      await queryClient.invalidateQueries({ queryKey: dashboardKeys.all });
      queryClient.setQueryData(documentKeys.detail(saved.id), saved);

      // Un DEVIS sans chantier en ouvre un, au nom de son objet (règle du
      // 2026-08-25, cf. `DocumentWriteService::withAutoProject()`). Le
      // répertoire des projets vient donc de changer : sans cette invalidation,
      // l'écran « Avancement de projet » servirait son cache et resterait à
      // zéro projet jusqu'au rechargement complet de la page — exactement le
      // symptôme qu'on vient de corriger côté serveur.
      //
      // Invalidé sur la seule présence d'un `projectId` : le devis peut aussi
      // avoir REJOINT un chantier existant, auquel cas rien n'est créé mais son
      // nombre de pièces rattachées, lui, a bougé.
      if (saved.projectId !== null) {
        await queryClient.invalidateQueries({ queryKey: projectKeys.all });
      }

      // Un nom saisi librement a ouvert une fiche client. L'annoncer n'est pas
      // décoratif : la fiche naît avec le seul nom, sans ICE ni adresse, et ces
      // mentions sont obligatoires au pied d'une facture (§3). Sans ce rappel,
      // personne ne saurait qu'il reste quelque chose à compléter.
      if (saved.autoCreatedPartnerName) {
        // Le répertoire vient de changer : le déroulant de tiers doit le
        // savoir, sans quoi la fiche resterait absente jusqu'au rechargement.
        await queryClient.invalidateQueries({ queryKey: partnerKeys.all });

        toast.success(t("form.partnerCreated.title"), {
          description: t("form.partnerCreated.description", {
            name: saved.autoCreatedPartnerName,
          }),
          // Plus long que le défaut : la phrase demande une action différée, et
          // quatre secondes ne suffisent pas à la lire puis à décider.
          duration: 8000,
        });
      }

      onOpenChange(false);
    },
    onError: (error) => {
      applyProblemToForm(error, form.setError, SERVER_FIELDS);
    },
  });

  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  const currency = editing?.currency ?? document?.currency ?? DEFAULT_CURRENCY;

  // Le formulaire n'existe pas tant que les lignes ne sont pas là : montrer des
  // champs vides puis les remplir sous les doigts de l'utilisateur est pire
  // qu'une attente d'un dixième de seconde.
  const loadingDetail = isEdit && editing === null && !detailQuery.isError;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-5xl">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t(`form.editTitle.${type}`) : t(`form.createTitle.${type}`)}
          </DialogTitle>
          <DialogDescription>
            {isEdit ? t("form.editDescription") : t("form.createDescription")}
          </DialogDescription>
        </DialogHeader>

        {loadingDetail ? (
          <div className="space-y-3 py-4">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-9 w-2/3" />
            <Skeleton className="h-32 w-full" />
          </div>
        ) : detailQuery.isError ? (
          <div className="py-4">
            <ErrorState
              detail={toApiProblem(detailQuery.error).detail}
              onRetry={() => void detailQuery.refetch()}
            />
          </div>
        ) : (
        <form
          id="document-form"
          onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          className="max-h-[65vh] space-y-4 overflow-y-auto pe-1"
        >
          {errors.root?.server && (
            <p className="text-destructive text-sm" role="alert">
              {errors.root.server.message}
            </p>
          )}

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="document-partner">
                {t("form.partner")}
              </FieldLabel>
              <Controller
                control={form.control}
                name="partnerId"
                render={({ field }) => (
                  // Recherchable (2026-08-26) : le répertoire d'un cabinet
                  // compte des centaines de fiches, et un déroulant ne se
                  // parcourt qu'à la molette.
                  //
                  // L'entrée « saisie rapide » du déroulant disparaît avec lui :
                  // ne RIEN choisir revient au même, et le champ « nom du
                  // client » qui apparaît juste en dessous porte déjà
                  // l'explication. Re-cliquer sur le tiers sélectionné le
                  // retire — c'est le chemin de retour vers la saisie libre.
                  <Combobox
                    id="document-partner"
                    options={partnerOptions}
                    value={field.value}
                    onChange={field.onChange}
                    placeholder={t("form.walkInClient")}
                    searchPlaceholder={t("form.searchPartner")}
                    emptyLabel={t("form.noPartnerFound")}
                  />
                )}
              />
              <FieldError>{fieldError(errors.partnerId?.message)}</FieldError>
            </Field>

            {/* PROJET, affiché seulement quand un client répertorié est
                choisi : les projets appartiennent à un client, et il n'y a
                rien à proposer sans lui. Un déroulant vide et grisé
                laisserait croire que le client n'a aucun chantier, alors
                qu'aucune question n'a encore été posée. */}
            {partnerId !== "" && (
              <Field className="sm:col-span-2">
                <FieldLabel htmlFor="document-project">
                  {t("form.project")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="projectId"
                  render={({ field }) => (
                    <Select
                      // Radix refuse la chaîne vide comme valeur d'item : le
                      // sentinel porte « aucun projet » dans la liste et
                      // redevient "" dans le formulaire.
                      value={field.value === "" ? NO_PROJECT : field.value}
                      onValueChange={(value) =>
                        field.onChange(value === NO_PROJECT ? "" : value)
                      }
                      disabled={projectsPending}
                    >
                      <SelectTrigger id="document-project" className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {/* Sur un DEVIS, ne rien choisir n'est plus « aucun
                            projet » : le serveur en ouvre un au nom de l'objet
                            (règle du 2026-08-25). L'option annonce donc ce
                            qu'elle fait — la laisser s'appeler « Aucun projet »
                            proposerait un choix sans effet. */}
                        <SelectItem value={NO_PROJECT}>
                          {type === "quote"
                            ? t("form.projectFromSubject")
                            : t("form.noProject")}
                        </SelectItem>
                        {projects.map((project) => (
                          <SelectItem key={project.id} value={project.id}>
                            {project.title}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
                {/* Le client n'a aucun chantier ouvert : le dire vaut mieux
                    qu'un déroulant à une seule entrée « aucun projet », dont
                    on ne saurait pas s'il est vide ou encore en train de
                    charger. */}
                {type === "quote" ? (
                  <p className="text-muted-foreground text-xs">
                    {t("form.projectFromSubjectHint")}
                  </p>
                ) : (
                  !projectsPending &&
                  projects.length === 0 && (
                    <p className="text-muted-foreground text-xs">
                      {t("form.noProjectForClient")}
                    </p>
                  )
                )}
                <FieldError>{fieldError(errors.projectId?.message)}</FieldError>
              </Field>
            )}

            {/* Masqué dès qu'un tiers est choisi : le serveur recopie alors sa
                raison sociale, et une saisie libre serait ignorée. */}
            {partnerId === "" && (
              <Field className="sm:col-span-2">
                <FieldLabel htmlFor="document-client-name">
                  {t("form.clientName")}
                </FieldLabel>
                <Input
                  id="document-client-name"
                  placeholder={t("form.clientPlaceholder")}
                  aria-invalid={Boolean(errors.clientName)}
                  aria-describedby="document-client-name-hint"
                  {...form.register("clientName")}
                />
                {/* Dit AVANT l'enregistrement ce que le toast confirmera
                    après : une fiche va naître. Découvrir la création une fois
                    faite est un moins bon moment pour l'apprendre. */}
                <p
                  id="document-client-name-hint"
                  className="text-muted-foreground text-xs"
                >
                  {t("form.clientNameHint")}
                </p>
                <FieldError>{fieldError(errors.clientName?.message)}</FieldError>
              </Field>
            )}

            {/* Objet du document : c'est lui qui s'imprime en tête du devis,
                sous le maître d'ouvrage. Facultatif — un document sans objet
                se lit dans ses lignes. */}
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="document-subject">
                {t("form.subject")}
              </FieldLabel>
              <Input
                id="document-subject"
                placeholder={t("form.subjectPlaceholder")}
                aria-invalid={Boolean(errors.subject)}
                {...form.register("subject")}
              />
              <FieldError>{fieldError(errors.subject?.message)}</FieldError>
            </Field>

            {/* Ville d'établissement : elle s'imprime en tête du devis, avant
                la date. Un bureau de contrôle établit ses documents là où se
                trouve le chantier, pas à son siège — d'où la saisie par
                document. Vide, l'impression retombe sur RABAT. */}
            <Field>
              <FieldLabel htmlFor="document-issue-city">
                {t("form.issueCity")}
              </FieldLabel>
              <Input
                id="document-issue-city"
                placeholder={t("form.issueCityPlaceholder")}
                aria-invalid={Boolean(errors.issueCity)}
                {...form.register("issueCity")}
              />
              <FieldError>{fieldError(errors.issueCity?.message)}</FieldError>
            </Field>

            {/* NUMÉRO. À la CRÉATION, c'est un compteur facultatif imposé à
                la séquence ; en MODIFICATION, c'est le numéro de la pièce, que
                la facture et le devis peuvent réécrire depuis le 2026-08-18
                (cf. DocumentType::allowsNumberEdit, qui porte le coût de cette
                levée). Les autres types ne l'affichent pas en modification :
                le serveur y répondrait 422.

                `inputMode` suit le mode : des chiffres à la création, du texte
                en modification, où le numéro complet se saisit. `type="number"`
                serait faux dans les deux cas — il ôte les zéros initiaux et
                affiche des flèches d'incrément qui n'ont aucun sens ici. */}
            {(!isEdit || canEditNumber) && (
              <Field>
                <FieldLabel htmlFor="document-number">
                  {t("form.number")}
                </FieldLabel>
                <Input
                  id="document-number"
                  inputMode={isEdit ? "text" : "numeric"}
                  autoComplete="off"
                  placeholder={t("form.numberPlaceholder")}
                  aria-invalid={Boolean(errors.number)}
                  aria-describedby="document-number-hint"
                  {...form.register("number")}
                />
                <p
                  id="document-number-hint"
                  className="text-muted-foreground text-xs"
                >
                  {isEdit ? t("form.numberEditHint") : t("form.numberHint")}
                </p>
                <FieldError>{fieldError(errors.number?.message)}</FieldError>
              </Field>
            )}

            <Field>
              <FieldLabel htmlFor="document-issued-at">
                {t("form.issuedAt")}
              </FieldLabel>
              <Input
                id="document-issued-at"
                type="date"
                aria-invalid={Boolean(errors.issuedAt)}
                {...form.register("issuedAt")}
              />
              <FieldError>{fieldError(errors.issuedAt?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="document-due-at">
                {t("form.dueAt")}
              </FieldLabel>
              <Input
                id="document-due-at"
                type="date"
                aria-invalid={Boolean(errors.dueAt)}
                {...form.register("dueAt")}
              />
              <FieldError>{fieldError(errors.dueAt?.message)}</FieldError>
            </Field>
          </div>

          <DocumentLineEditor
            control={form.control}
            register={form.register}
            setValue={form.setValue}
            products={products}
            taxRates={taxRates}
            defaultTaxRateId={defaultTaxRateId}
            currency={currency}
          />

          {/* Pied de document : aperçu local, remplacé par les montants du
              serveur dès l'enregistrement. */}
          <div className="flex justify-end">
            <dl className="ring-border w-full max-w-72 space-y-1 rounded-lg px-3 py-2 text-sm ring-1">
              <div className="flex justify-between">
                <dt className="text-muted-foreground">{t("totals.subtotal")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.subtotalCents, locale, currency)}
                </dd>
              </div>
              {totals.discountCents > 0 && (
                <div className="flex justify-between">
                  <dt className="text-muted-foreground">
                    {t("totals.discount")}
                  </dt>
                  <dd className="tabular">
                    −{formatMoney(totals.discountCents, locale, currency)}
                  </dd>
                </div>
              )}
              <div className="flex justify-between">
                <dt className="text-muted-foreground">{t("totals.tax")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.taxCents, locale, currency)}
                </dd>
              </div>
              <div className="border-border flex justify-between border-t pt-1 font-semibold">
                <dt>{t("totals.total")}</dt>
                <dd className="tabular">
                  {formatMoney(totals.totalCents, locale, currency)}
                </dd>
              </div>
            </dl>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="document-notes">{t("form.notes")}</FieldLabel>
              <Textarea id="document-notes" rows={2} {...form.register("notes")} />
              <FieldError>{fieldError(errors.notes?.message)}</FieldError>
            </Field>

            <Field>
              <FieldLabel htmlFor="document-terms">{t("form.terms")}</FieldLabel>
              <Textarea id="document-terms" rows={2} {...form.register("terms")} />
              <FieldError>{fieldError(errors.terms?.message)}</FieldError>
            </Field>
          </div>
        </form>
        )}

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            {t("form.cancel")}
          </Button>
          {/* Enregistrer reste hors du formulaire (`form=`) mais suit son
              état : tant que le détail charge, il n'y a rien à soumettre.

              Le libellé DIFFÈRE à la création : depuis le 2026-08-14, valider
              ne met pas un brouillon de côté, cela consomme un numéro fiscal
              définitif. Un bouton « Enregistrer » laisserait croire au geste
              réversible qu'il n'est plus. En édition, il l'est resté. */}
          <Button
            type="submit"
            form="document-form"
            loading={mutation.isPending || loadingDetail}
            disabled={detailQuery.isError}
          >
            {isEdit
              ? t(mutation.isPending ? "form.saving" : "form.save")
              : t(mutation.isPending ? "form.submitting" : "form.submit")}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
