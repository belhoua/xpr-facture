"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
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
import { useSearchParams } from "next/navigation";
import { useState } from "react";

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
import { DocumentDetailSheet } from "@/features/documents/components/document-detail-sheet";
import { DocumentFormDialog } from "@/features/documents/components/document-form-dialog";
import { PaymentsModal } from "@/features/payments/components/payments-modal";
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

/** Une heure : le référentiel de TVA et le catalogue ne bougent pas en séance. */
const REFERENCE_STALE_TIME = 60 * 60 * 1000;

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

  const filters = { type, search, status };
  const { data, isPending, isError, error, refetch } = useQuery({
    queryKey: documentKeys.list(filters),
    queryFn: () => fetchDocuments(filters),
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
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: documentKeys.all });
      setDeleteTarget(null);
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

      <DocumentFormDialog
        open={formOpen}
        onOpenChange={setFormOpen}
        type={type}
        document={editing}
      />

      <DocumentDetailSheet
        documentId={detailId ?? deepLinkId}
        onOpenChange={(open) => !open && openDetail(null)}
        onEdit={(document) => {
          openDetail(null);
          openEdit(document);
        }}
        // Conversion et avoir produisent un NOUVEAU document, d'un autre type
        // que celui de cet écran : il part dans SA liste, panneau ouvert — le
        // même trajet que depuis le menu de la table, sinon la même action
        // aboutirait à deux endroits différents selon l'endroit d'où on la
        // déclenche.
        onConverted={(created) => void settleTransfer(created)}
      />

      {/* Deux avertissements distincts, parce que les deux gestes n'ont pas la
          même portée : jeter un brouillon ne laisse aucune trace, supprimer une
          pièce NUMÉROTÉE troue définitivement la séquence. Un texte unique
          finirait par mentir dans l'un des deux cas. */}
      <ConfirmDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={
          deletingNumber === null
            ? t("delete.title")
            : t("delete.issuedTitle", { number: deletingNumber })
        }
        description={
          deletingNumber === null
            ? t("delete.description")
            : t("delete.issuedDescription", { number: deletingNumber })
        }
        confirmLabel={t("delete.confirm")}
        pending={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget.id)}
      />

      <PaymentsModal
        invoiceId={paymentTarget?.id ?? null}
        invoiceNumber={paymentTarget?.number ?? null}
        open={paymentTarget !== null}
        onOpenChange={(next) => !next && setPaymentTarget(null)}
      />
    </>
  );
}
