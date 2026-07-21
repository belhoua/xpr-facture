import {
  FileText,
  LayoutDashboard,
  MessageSquareText,
  Users,
  Wallet,
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

/** Aplatissement pour la palette ⌘K et la résolution du fil d'Ariane. */
export const NAV_ITEMS: readonly NavItem[] = NAVIGATION.flatMap(
  (group) => group.items,
);
