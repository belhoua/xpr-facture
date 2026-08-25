"use client";

import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from "@tanstack/react-query";
import {
  FileOutput,
  FileSignature,
  FileText,
  Lock,
  MoreHorizontal,
  Pencil,
  Plus,
  Printer,
  Search,
  Trash2,
  Wallet,
} from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import dynamic from "next/dynamic";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { toast } from "sonner";

import { ConfirmDialog } from "@/components/patterns/confirm-dialog";
import { DataTable, type Column } from "@/components/patterns/data-table";
import { PageHeader } from "@/components/patterns/page-header";
import { StatusBadge } from "@/components/patterns/status-badge";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { catalogKeys, fetchProducts, fetchTaxRates } from "@/features/catalog/api/catalog";
import {
  conventionKeys,
  createConventionFromDocument,
} from "@/features/conventions/api/conventions";
import {
  convertDocument,
  deleteDocument,
  documentKeys,
  fetchDocuments,
} from "@/features/documents/api/documents";
import { dashboardKeys } from "@/features/dashboard/api/dashboard";
import {
  assignableStatuses,
  isConvertible,
  isDeletable,
  isEditable,
  isTransferableToConvention,
  listRoute,
  printRoute,
  type Document,
  type DocumentType,
} from "@/features/documents/schemas/document";
import { toApiProblem } from "@/lib/api/client";
import { formatDate, formatMoney } from "@/lib/format";
import { Link, usePathname, useRouter } from "@/lib/i18n/navigation";
import { useDeferredMount } from "@/lib/use-deferred-mount";
import { useDebouncedValue } from "@/lib/use-debounced-value";
import { REFERENCE_STALE_TIME } from "@/lib/api/stale-times";

/**
 * Les TROIS panneaux lourds de cet écran sont chargés à la demande.
 *
 * Le formulaire de document, le panneau de détail et la fenêtre des règlements
 * représentent à eux seuls plus de code que la liste qui les héberge — le
 * formulaire embarque l'éditeur de lignes, ses calculs de totaux et ses
 * sélecteurs de catalogue. Importés statiquement, ils partaient dans le lot
 * initial de /invoices et /quotes, alors que l'écran s'ouvre sur un tableau et
 * que la plupart des consultations n'en ouvrent aucun.
 *
 * `useDeferredMount` retient la première ouverture : le téléchargement a lieu
 * au clic, puis le composant reste monté (cf. le commentaire du hook).
 */
const DocumentFormDialog = dynamic(
  () =>
    import("@/features/documents/components/document-form-dialog").then(
      (m) => m.DocumentFormDialog,
    ),
  { ssr: false },
);

const DocumentDetailSheet = dynamic(
  () =>
    import("@/features/documents/components/document-detail-sheet").then(
      (m) => m.DocumentDetailSheet,
    ),
  { ssr: false },
);

const PaymentsModal = dynamic(
  () =>
    import("@/features/payments/components/payments-modal").then(
      (m) => m.PaymentsModal,
    ),
  { ssr: false },
);


/**
 * Liste des documents d'un TYPE donné — factures, devis, avoirs partagent cet
 * écran, seule leur enveloppe change. Le type n'est pas un filtre d'interface
 * mais un paramètre de la page : « Factures » et « Devis » sont deux écrans
 * distincts dans la navigation, avec chacun leur cycle de vie.
 *
 * Les filtres font partie de la CLÉ de requête TanStack Query ; les mutations
 * invalident `documentKeys.all` plutôt que de patcher chaque cache filtré.
 *
 * Cet écran PRÉCHARGE le catalogue et les taux de TVA : ils sont figés à
 * l'échelle d'une séance, et les avoir en cache évite au formulaire de
 * s'ouvrir sur des sélecteurs vides.
 */
export function DocumentsView({ type }: { type: DocumentType }) {
  const t = useTranslations("documents");
  const tStatus = useTranslations("status");
  const tCommon = useTranslations("common");
  const locale = useLocale();
  const queryClient = useQueryClient();
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const [search, setSearch] = useState("");

  // La valeur INTERROGÉE est retardée ; le champ, lui, reste immédiat.
  // Sans cela, chaque caractère frappé partait en requête (cf. le hook).
  const debouncedSearch = useDebouncedValue(search);
  const [status, setStatus] = useState("all");

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Document | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Document | null>(null);
  // Règlements : réservés aux FACTURES, l'entrée de menu n'apparaît pas
  // ailleurs. Le serveur le tient de son côté — un devis reçoit 409.
  const [paymentTarget, setPaymentTarget] = useState<Document | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  /**
   * `?document=<id>` ouvre le panneau de détail au chargement de l'écran.
   *
   * C'est ce qui donne son sens au transfert : la facture issue d'un devis naît
   * dans une AUTRE liste que celle où l'on était, et on y arrive avec elle
   * déjà ouverte. Le paramètre est aussi une URL partageable vers une pièce.
   *
   * Dérivé et non recopié dans un état par un effet : l'état ne peut pas se
   * retrouver en retard d'un rendu sur l'URL. Le premier geste de l'utilisateur
   * (ouvrir une autre ligne, ou fermer) retire le paramètre — sans quoi, une
   * fois refermé, le panneau se rouvrirait sur ce même document.
   */
  const deepLinkId = searchParams.get("document");
  const openDetail = (id: string | null) => {
    setDetailId(id);

    if (deepLinkId !== null) {
      router.replace(pathname);
    }
  };

  // Montage différé des trois panneaux lourds : le code n'est demandé qu'à la
  // première ouverture de chacun (cf. le bloc `dynamic` en tête de fichier).
  const formMounted = useDeferredMount(formOpen);
  const detailMounted = useDeferredMount((detailId ?? deepLinkId) !== null);
  const paymentsMounted = useDeferredMount(paymentTarget !== null);

  const filters = { type, search: debouncedSearch, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: documentKeys.list(filters),
    queryFn: () => fetchDocuments(filters),
    // La liste PRÉCÉDENTE reste affichée pendant que la nouvelle arrive :
    // sans cela, chaque recherche renvoyait le tableau à ses squelettes,
    // et l'écran clignotait à chaque pause de frappe.
    placeholderData: keepPreviousData,
  });

  useQuery({
    queryKey: catalogKeys.taxRates(),
    queryFn: fetchTaxRates,
    staleTime: REFERENCE_STALE_TIME,
  });

  useQuery({
    queryKey: catalogKeys.productList({
      search: "",
      type: "all",
      categoryId: "all",
    }),
    queryFn: () => fetchProducts({ search: "", type: "all", categoryId: "all" }),
    staleTime: REFERENCE_STALE_TIME,
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => deleteDocument(id),
    // Le document supprimé est capturé AVANT l'appel : `deleteTarget` est remis
    // à null en fin d'opération, et le lire ensuite pour composer le message
    // donnerait une phrase sans numéro.
    onSuccess: async (_result, id) => {
      const removed = deleteTarget?.id === id ? deleteTarget : null;

      await queryClient.invalidateQueries({ queryKey: documentKeys.all });
      // Le chiffre d'affaires et le restant dû du tableau de bord se lisent sur
      // les factures : une pièce supprimée les change tous les deux.
      await queryClient.invalidateQueries({ queryKey: dashboardKeys.all });
      setDeleteTarget(null);

      // La ligne disparaît, ce qui se voit déjà. Le toast dit ce que la
      // disparition ne dit pas : LAQUELLE est partie — sur une liste filtrée,
      // une ligne qui s'efface peut tout aussi bien être sortie du filtre.
      //
      // Le message dit « document » et non « devis » : cette vue sert les deux
      // écrans, et sa prop `type` en accepte huit. Nommer le type demanderait
      // une clé par type — sept d'entre elles pour des écrans qui n'existent
      // pas — ou produirait « le devis » sur `/invoices` au premier oubli.
      toast.success(
        removed?.number === null || removed?.number === undefined
          ? t("delete.doneDraft")
          : t("delete.done", { number: removed.number }),
      );
    },
  });

  /**
   * Aboutissement commun des deux transferts : le document créé est mis en
   * cache, les listes invalidées (celle-ci a changé — un devis converti passe
   * `converted` — et celle d'arrivée a un élément de plus), puis on OUVRE la
   * pièce produite dans sa propre liste.
   *
   * La redirection n'est pas cosmétique : le transfert crée un BROUILLON, qu'il
   * reste à vérifier puis à émettre. Laisser l'utilisateur sur la liste de
   * départ lui cacherait le travail qui commence.
   */
  const settleTransfer = async (created: Document) => {
    queryClient.setQueryData(documentKeys.detail(created.id), created);
    await queryClient.invalidateQueries({ queryKey: documentKeys.all });

    setActionError(null);

    const destination = listRoute(created.type);

    if (destination === null) {
      // Type sans écran de liste : on n'a nulle part où aller, mais le document
      // existe — mieux vaut le dire que rediriger vers une page inexistante.
      setDetailId(created.id);

      return;
    }

    router.push(`${destination}?document=${created.id}`);
  };

  const failTransfer = (cause: unknown) => {
    const problem = toApiProblem(cause);

    // Le serveur reste juge de la transition (409 si le devis a déjà été
    // converti, si la facture est annulée…) : on réaffiche SON message plutôt
    // que d'en inventer un depuis l'interface.
    setActionError(problem.detail ?? problem.title);
  };

  const convertMutation = useMutation({
    mutationFn: (id: string) => convertDocument(id),
    onSuccess: settleTransfer,
    onError: failTransfer,
  });

  /**
   * Devis / facture → contrat de convention.
   *
   * Ne passe PAS par `settleTransfer` : ce transfert ne produit pas un document
   * mais une CONVENTION, qui n'a ni le même contrat JSON ni la même liste. Le
   * document source, lui, n'est pas consommé — inutile d'invalider les listes
   * de documents, rien n'y a changé.
   *
   * On atterrit sur l'écran d'ÉDITION et non sur la liste : la convention naît
   * incomplète par construction (titre foncier, lots réellement contrôlés,
   * délai), et c'est le travail qui commence.
   */
  const conventionMutation = useMutation({
    mutationFn: (id: string) => createConventionFromDocument(id),
    onSuccess: async (created) => {
      queryClient.setQueryData(conventionKeys.detail(created.id), created);
      await queryClient.invalidateQueries({ queryKey: conventionKeys.all });
      setActionError(null);
      router.push(`/conventions/${created.id}/edit`);
    },
    onError: failTransfer,
  });

  const transferring =
    convertMutation.isPending || conventionMutation.isPending;

  const openCreate = () => {
    setEditing(null);
    setFormOpen(true);
  };

  const openEdit = (document: Document) => {
    setEditing(document);
    setFormOpen(true);
  };

  /**
   * Numéro de la pièce en cours de suppression, `null` pour un brouillon.
   *
   * C'est lui, et non le statut, qui décide de l'avertissement : un document
   * sans numéro n'a rien consommé dans la séquence, quel que soit son état.
   */
  const deletingNumber = deleteTarget?.number ?? null;

  /**
   * Le devis en cours de suppression a-t-il déjà produit une facture ?
   *
   * Troisième cas d'avertissement depuis le 2026-08-24, aux côtés du brouillon
   * et de la pièce simplement numérotée : celui-ci laisse derrière lui une
   * FACTURE, qui ne conservera de son devis que le numéro. C'est ce que la
   * levée coûte, et c'est le moment de le dire — les deux textes existants
   * parlent du trou dans la séquence, aucun ne parle de la pièce qui reste.
   */
  const deletingConverted = deleteTarget?.status === "converted";

  /**
   * Statuts filtrables : ceux du cycle du type, plus `cancelled`.
   *
   * `draft` en est retiré depuis le 2026-08-15 : la facture et le devis
   * naissent numérotés, le produit n'en crée donc plus aucun. Proposer un
   * filtre qui ne peut plus rien ramener, c'est promettre une liste vide.
   */
  const statusFilters = ["all", ...assignableStatuses(type), "cancelled"];

  const columns: readonly Column<Document>[] = [
    {
      id: "number",
      header: t("columns.number"),
      cell: (row) => (
        <span className="tabular font-medium">
          {row.number ?? (
            <span className="text-muted-foreground">{t("draftLabel")}</span>
          )}
        </span>
      ),
    },
    {
      id: "client",
      header: t("columns.client"),
      cell: (row) => row.clientName,
    },
    {
      id: "issuedAt",
      header: t("columns.issuedAt"),
      hideBelow: "md",
      cell: (row) => (row.issuedAt ? formatDate(row.issuedAt, locale) : "—"),
    },
    {
      id: "dueAt",
      header: t("columns.dueAt"),
      hideBelow: "lg",
      cell: (row) => (row.dueAt ? formatDate(row.dueAt, locale) : "—"),
    },
    {
      id: "status",
      header: t("columns.status"),
      cell: (row) => (
        <StatusBadge status={row.status} label={tStatus(row.status)} />
      ),
    },
    {
      id: "total",
      header: t("columns.total"),
      align: "end",
      cell: (row) => (
        <span className="tabular font-medium">
          {formatMoney(row.totalCents, locale, row.currency)}
        </span>
      ),
    },
    {
      id: "actions",
      header: tCommon("actions"),
      align: "end",
      cell: (row) => {
        const printPath = printRoute(row);

        return (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label={t("actions.open")}
                // Le clic sur l'action ne doit pas aussi ouvrir le détail :
                // la ligne entière est cliquable.
                onClick={(event) => event.stopPropagation()}
                className="opacity-60 transition-opacity group-hover/row:opacity-100 data-[state=open]:opacity-100"
              >
                <MoreHorizontal aria-hidden />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
              <DropdownMenuItem onSelect={() => openDetail(row.id)}>
                <FileText aria-hidden />
                {t("actions.view")}
              </DropdownMenuItem>

              {/* RÈGLEMENTS — factures seulement. Un devis n'ouvre aucune
                  créance : il n'y a rien à solder tant qu'il n'est pas devenu
                  facture. Le brouillon en est exclu aussi : sans numéro, la
                  pièce n'existe pas fiscalement et l'encaissement n'aurait
                  rien à quoi se rattacher. */}
              {row.type === "invoice" && row.status !== "draft" && (
                <DropdownMenuItem onSelect={() => setPaymentTarget(row)}>
                  <Wallet aria-hidden />
                  {t("actions.payments")}
                </DropdownMenuItem>
              )}

              {/* Transferts. La même règle que le panneau de détail —
                  `isConvertible` — parce que c'est le même acte : un devis
                  ÉMIS devient une facture. Un brouillon n'est pas
                  transférable : il n'engage encore rien. */}
              {isConvertible(row) && (
                <DropdownMenuItem
                  disabled={transferring}
                  onSelect={() => convertMutation.mutate(row.id)}
                >
                  <FileOutput aria-hidden />
                  {t("actions.convert")}
                </DropdownMenuItem>
              )}

              {/* Transfert en CONVENTION. Séparé des deux précédents : il ne
                  produit pas un document commercial mais un contrat de mission,
                  et il ne consomme pas le devis — on peut parfaitement le
                  convertir en facture ensuite, ce qui est même l'ordre normal :
                  on signe la convention, puis on facture l'avance. */}
              {isTransferableToConvention(row) && (
                <DropdownMenuItem
                  disabled={transferring}
                  onSelect={() => conventionMutation.mutate(row.id)}
                >
                  <FileSignature aria-hidden />
                  {t("actions.convention")}
                </DropdownMenuItem>
              )}

              {/* Chaque type a son gabarit A4 ; `printRoute` ne rend une route
                  que pour ceux qui en ont un. Les avoirs n'en ont pas encore :
                  l'entrée disparaît plutôt que d'ouvrir un modèle faux. */}
              {printPath !== null && (
                <DropdownMenuItem asChild>
                  <Link href={printPath}>
                    <Printer aria-hidden />
                    {t("actions.print")}
                  </Link>
                </DropdownMenuItem>
              )}

              {/* Immuabilité fiscale (§3) et ses levées. Les deux actes sont
                  jugés SÉPARÉMENT : un devis émis se modifie (2026-08-06) mais
                  ne se supprime pas, sa séquence `DEV-` devant rester continue.
                  Les deux prédicats reflètent le serveur, ils ne le décident
                  pas. */}
              {isEditable(row) && (
                <DropdownMenuItem onSelect={() => openEdit(row)}>
                  <Pencil aria-hidden />
                  {t("actions.edit")}
                </DropdownMenuItem>
              )}

              {isDeletable(row) && (
                <DropdownMenuItem
                  variant="destructive"
                  onSelect={() => setDeleteTarget(row)}
                >
                  <Trash2 aria-hidden />
                  {t("actions.delete")}
                </DropdownMenuItem>
              )}

              {/* Modifiable MAIS pas supprimable — le cas d'un devis converti
                  ou refusé, que le 2026-08-07 rouvre à l'édition sans rouvrir
                  la suppression : effacer un devis converti couperait
                  `parent_document_id` et sa facture perdrait la trace de ce
                  dont elle découle.
                  L'entrée est désactivée plutôt qu'absente, pour la raison
                  déjà retenue plus bas : un menu qui montre « Modifier » sans
                  « Supprimer » et sans rien dire se lit comme un
                  dysfonctionnement, et c'est ce silence qui fait rouvrir le
                  sujet. */}
              {isEditable(row) && !isDeletable(row) && (
                <DropdownMenuItem disabled>
                  <Lock aria-hidden />
                  {t("actions.notDeletable")}
                </DropdownMenuItem>
              )}

              {/* Ni l'un ni l'autre : document clos (annulé, refusé, converti)
                  ou type gelé. Désactivé plutôt qu'absent — sans cette mention,
                  l'utilisateur qui cherche « Modifier » conclut à un
                  dysfonctionnement. Annulation et avoir restent offerts dans le
                  panneau de détail. */}
              {!isEditable(row) && !isDeletable(row) && (
                <DropdownMenuItem disabled>
                  <Lock aria-hidden />
                  {t("actions.frozen")}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        );
      },
    },
  ];

  return (
    <>
      <PageHeader
        title={t(`title.${type}`)}
        description={t(`description.${type}`)}
        actions={
          <Button size="sm" onClick={openCreate}>
            <Plus aria-hidden />
            {t(`create.${type}`)}
          </Button>
        }
      />

      {/* Un transfert refusé doit se voir : il part d'un menu, pas d'un
          formulaire, et sans ce retour l'utilisateur conclurait que le clic
          n'a rien fait. Pas de toast — le dépôt n'en a pas, et en ajouter un
          pour un cas d'erreur ne se justifie pas. */}
      {actionError !== null && (
        <p className="text-destructive mb-3 text-sm" role="alert">
          {actionError}
        </p>
      )}

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="relative min-w-56 flex-1 sm:max-w-80">
          <Search
            className="text-muted-foreground pointer-events-none absolute inset-y-0 start-2.5 my-auto size-4"
            aria-hidden
          />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder={t("searchPlaceholder")}
            aria-label={tCommon("search")}
            className="ps-8"
          />
        </div>

        <Select value={status} onValueChange={setStatus}>
          <SelectTrigger className="w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {statusFilters.map((value) => (
              <SelectItem key={value} value={value}>
                {value === "all" ? tCommon("all") : tStatus(value)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <DataTable
        rows={data?.data ?? []}
        columns={columns}
        rowKey={(row) => row.id}
        status={isPending ? "pending" : isError ? "error" : "success"}
        errorDetail={isError ? toApiProblem(error).detail : undefined}
        onRetry={() => void refetch()}
        onRowClick={(row) => openDetail(row.id)}
        empty={{
          icon: FileText,
          title: t(`empty.${type}.title`),
          description: t(`empty.${type}.description`),
          action: (
            <Button size="sm" onClick={openCreate}>
              <Plus aria-hidden />
              {t(`create.${type}`)}
            </Button>
          ),
        }}
      />

      {formMounted && (
        <DocumentFormDialog
          open={formOpen}
          onOpenChange={setFormOpen}
          type={type}
          document={editing}
        />
      )}

      {detailMounted && (
        <DocumentDetailSheet
          documentId={detailId ?? deepLinkId}
          onOpenChange={(open) => !open && openDetail(null)}
          onEdit={(document) => {
            openDetail(null);
            openEdit(document);
          }}
          // Conversion et avoir produisent un NOUVEAU document, d'un autre
          // type que celui de cet écran : il part dans SA liste, panneau
          // ouvert — le même trajet que depuis le menu de la table, sinon la
          // même action aboutirait à deux endroits différents selon l'endroit
          // d'où on la déclenche.
          onConverted={(created) => void settleTransfer(created)}
        />
      )}

      {/* TROIS avertissements distincts, parce que les trois gestes n'ont pas la
          même portée : jeter un brouillon ne laisse aucune trace, supprimer une
          pièce NUMÉROTÉE troue définitivement la séquence, et supprimer un devis
          CONVERTI laisse en plus une facture qui n'en gardera que le numéro. Un
          texte unique finirait par mentir dans deux cas sur trois. */}
      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={
          deletingNumber === null
            ? t("delete.title")
            : deletingConverted
              ? t("delete.convertedTitle", { number: deletingNumber })
              : t("delete.issuedTitle", { number: deletingNumber })
        }
        description={
          deletingNumber === null
            ? t("delete.description")
            : deletingConverted
              ? t("delete.convertedDescription", { number: deletingNumber })
              : t("delete.issuedDescription", { number: deletingNumber })
        }
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />

      {paymentsMounted && (
        <PaymentsModal
          invoiceId={paymentTarget?.id ?? null}
          invoiceNumber={paymentTarget?.number ?? null}
          open={paymentTarget !== null}
          onOpenChange={(next) => !next && setPaymentTarget(null)}
        />
      )}
    </>
  );
}
