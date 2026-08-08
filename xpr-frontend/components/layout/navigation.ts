import {
  ClipboardList,
  Contact,
  FileMinus,
  FilePlus,
  FileSignature,
  FileText,
  FolderInput,
  LayoutDashboard,
  MessageSquareText,
  Package,
  ReceiptText,
  Users,
  UsersRound,
  Wallet,
  Wrench,
  type LucideIcon,
} from "lucide-react";

/**
 * Source de vérité UNIQUE de la navigation applicative : la sidebar et la
 * command palette ⌘K la consomment toutes les deux. Ajouter un écran ici
 * l'ajoute automatiquement aux deux — c'est précisément ce qu'on veut éviter
 * de faire à deux endroits.
 *
 * `titleKey` pointe dans `messages/*.json` sous `nav.*` : aucun libellé en dur.
 */
export interface NavItem {
  href: string;
  titleKey: string;
  icon: LucideIcon;
  /** Raccourci clavier affiché dans la palette (⌘ + touche). */
  shortcut?: string;
  /**
   * Sous-entrées d'un menu déroulant. L'entrée parente reste un lien à part
   * entière — le chevron déplie, il ne remplace pas la navigation : un menu
   * qu'on ne peut qu'ouvrir oblige à deux gestes là où un suffisait.
   */
  children?: readonly NavItem[];
}

export interface NavGroup {
  titleKey: string;
  items: readonly NavItem[];
}

export const NAVIGATION: readonly NavGroup[] = [
  {
    titleKey: "nav.groups.overview",
    items: [
      {
        href: "/dashboard",
        titleKey: "nav.dashboard",
        icon: LayoutDashboard,
        shortcut: "D",
      },
    ],
  },
  {
    titleKey: "nav.groups.management",
    items: [
      { href: "/invoices", titleKey: "nav.invoices", icon: FileText, shortcut: "F" },
      { href: "/quotes", titleKey: "nav.quotes", icon: ReceiptText, shortcut: "V" },
      {
        href: "/credit-notes",
        titleKey: "nav.creditNotes",
        icon: FileMinus,
        shortcut: "A",
      },
      {
        href: "/situations",
        titleKey: "nav.situations",
        icon: ClipboardList,
        shortcut: "I",
        children: [
          { href: "/situations/create", titleKey: "nav.situationsCreate", icon: FilePlus },
          { href: "/situations", titleKey: "nav.situationsList", icon: ClipboardList },
          {
            href: "/situations/by-client",
            titleKey: "nav.situationsByClient",
            icon: UsersRound,
          },
        ],
      },
      {
        href: "/conventions",
        titleKey: "nav.conventions",
        icon: FileSignature,
        shortcut: "K",
        children: [
          {
            href: "/conventions/create",
            titleKey: "nav.conventionsCreate",
            icon: FilePlus,
          },
          {
            href: "/conventions",
            titleKey: "nav.conventionsList",
            icon: FileSignature,
          },
          // Le suivi des dépôts vit SOUS les conventions : un dépôt n'existe
          // que rattaché à un contrat, et lui donner une entrée de premier
          // niveau laisserait croire à un module autonome.
          { href: "/deposits", titleKey: "nav.deposits", icon: FolderInput },
        ],
      },
      { href: "/services", titleKey: "nav.services", icon: Wrench, shortcut: "S" },
      { href: "/catalog", titleKey: "nav.catalog", icon: Package, shortcut: "P" },
      { href: "/partners", titleKey: "nav.partners", icon: Contact, shortcut: "T" },
      { href: "/cash", titleKey: "nav.cash", icon: Wallet, shortcut: "C" },
    ],
  },
  {
    titleKey: "nav.groups.organisation",
    items: [
      { href: "/users", titleKey: "nav.users", icon: Users, shortcut: "U" },
      {
        href: "/admin-notes",
        titleKey: "nav.adminNotes",
        icon: MessageSquareText,
        shortcut: "N",
      },
    ],
  },
];

/**
 * Aplatissement pour la palette ⌘K et la résolution du fil d'Ariane.
 *
 * Les sous-entrées y figurent : « Ajouter une situation » doit être atteignable
 * au clavier sans passer par la sidebar. La DÉDUPLICATION par `href` est
 * nécessaire — l'entrée parente `/situations` est reprise dans ses enfants sous
 * le libellé « Liste des situations », et la palette afficherait deux fois la
 * même destination. La première occurrence gagne, donc le libellé du parent.
 */
export const NAV_ITEMS: readonly NavItem[] = Array.from(
  NAVIGATION.flatMap((group) =>
    group.items.flatMap((item) => [item, ...(item.children ?? [])]),
  )
    .reduce(
      (unique, item) =>
        unique.has(item.href) ? unique : unique.set(item.href, item),
      new Map<string, NavItem>(),
    )
    .values(),
);
