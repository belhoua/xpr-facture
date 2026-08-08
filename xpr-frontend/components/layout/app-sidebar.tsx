"use client";

import { ChevronDown, ChevronsLeft } from "lucide-react";
import { useTranslations } from "next-intl";
import { useState } from "react";

import { BrandIcon } from "@/components/layout/brand-icon";
import { BrandMark } from "@/components/layout/brand-mark";
import { NAVIGATION, type NavItem } from "@/components/layout/navigation";
import { Button } from "@/components/ui/button";
import { BRAND } from "@/lib/brand";
import { Link, usePathname } from "@/lib/i18n/navigation";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/stores/ui";

/**
 * Barre latérale de l'espace client. Elle se réduit à une colonne d'icônes
 * (68px) plutôt que de disparaître : sur un outil qu'on utilise tous les jours,
 * garder les repères visuels vaut mieux que gagner 200px.
 *
 * Aucune classe directionnelle (`left`, `ml`, `border-l`) : uniquement des
 * propriétés logiques, pour que le RTL arabe fonctionne sans code dédié.
 */
/**
 * Une entrée de navigation, avec son éventuel sous-menu.
 *
 * L'entrée parente reste un LIEN : le chevron déplie, il ne capture pas le
 * clic. Un menu qu'on ne peut qu'ouvrir imposerait deux gestes pour atteindre
 * l'écran principal, là où un suffisait.
 */
function NavEntry({ item, collapsed }: { item: NavItem; collapsed: boolean }) {
  const t = useTranslations();
  const pathname = usePathname();
  const [manuallyToggled, setManuallyToggled] = useState<boolean | null>(null);

  const children = item.children ?? [];
  const hasChildren = children.length > 0 && !collapsed;

  // Un descendant actif ouvre le menu de lui-même ; un clic sur le chevron
  // prend ensuite le pas. Dérivé plutôt que synchronisé par un effet : l'état
  // ne peut pas se retrouver en retard d'un rendu sur l'URL.
  const containsActive =
    pathname === item.href || pathname.startsWith(`${item.href}/`);
  const open = manuallyToggled ?? containsActive;

  // « Le plus spécifique gagne » : sur /situations/create, seule l'entrée
  // « Ajouter » s'allume — pas « Liste des situations », dont le href est
  // pourtant un préfixe de l'URL courante.
  const activeHref = [item, ...children]
    .map((entry) => entry.href)
    .filter(
      (href) => pathname === href || pathname.startsWith(`${href}/`),
    )
    .sort((a, b) => b.length - a.length)[0];

  const parentActive = activeHref === item.href;
  const submenuId = `nav-${item.href.replace(/\W+/g, "-")}`;

  return (
    <li>
      <div className="relative flex items-center">
        <Link
          href={item.href}
          aria-current={parentActive ? "page" : undefined}
          title={collapsed ? t(item.titleKey) : undefined}
          className={cn(
            "group relative flex h-8 flex-1 items-center gap-2.5 rounded-md px-2 text-sm font-medium transition-colors",
            "focus-visible:ring-sidebar-ring focus-visible:ring-2 focus-visible:outline-none",
            collapsed && "justify-center px-0",
            parentActive
              ? "bg-sidebar-accent text-sidebar-accent-foreground"
              : "text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground",
          )}
        >
          {/* Le marqueur d'état actif est un liseré en DÉBUT de
              ligne — il bascule seul à droite en arabe. */}
          {parentActive && !collapsed && (
            <span
              aria-hidden
              className="bg-sidebar-primary absolute inset-y-1.5 start-0 w-0.5 rounded-full"
            />
          )}
          <item.icon
            className={cn("size-4 shrink-0", parentActive && "text-sidebar-primary")}
            aria-hidden
          />
          {!collapsed && <span className="truncate">{t(item.titleKey)}</span>}
        </Link>

        {hasChildren && (
          <button
            type="button"
            onClick={() => setManuallyToggled(!open)}
            aria-expanded={open}
            aria-controls={submenuId}
            aria-label={t(item.titleKey)}
            className={cn(
              "text-muted-foreground hover:text-sidebar-accent-foreground hover:bg-sidebar-accent/60",
              "focus-visible:ring-sidebar-ring me-0.5 grid size-6 shrink-0 place-items-center rounded",
              "focus-visible:ring-2 focus-visible:outline-none",
            )}
          >
            <ChevronDown
              className={cn(
                "size-3.5 transition-transform duration-150",
                open && "rotate-180",
              )}
              aria-hidden
            />
          </button>
        )}
      </div>

      {hasChildren && open && (
        // `ms-*` et `border-s` : le décalage du sous-menu et son filet passent
        // seuls à droite en arabe, sans règle RTL dédiée.
        <ul
          id={submenuId}
          className="border-sidebar-border ms-4 mt-0.5 space-y-0.5 border-s ps-2"
        >
          {children.map((child) => {
            const childActive = activeHref === child.href;

            return (
              <li key={`${item.href}-${child.href}`}>
                <Link
                  href={child.href}
                  aria-current={childActive ? "page" : undefined}
                  className={cn(
                    "flex h-7 items-center gap-2 rounded-md px-2 text-[0.8125rem] transition-colors",
                    "focus-visible:ring-sidebar-ring focus-visible:ring-2 focus-visible:outline-none",
                    childActive
                      ? "bg-sidebar-accent text-sidebar-accent-foreground font-medium"
                      : "text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground",
                  )}
                >
                  <child.icon
                    className={cn(
                      "size-3.5 shrink-0",
                      childActive && "text-sidebar-primary",
                    )}
                    aria-hidden
                  />
                  <span className="truncate">{t(child.titleKey)}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}
    </li>
  );
}

export function AppSidebar() {
  const t = useTranslations();
  const collapsed = useUiStore((state) => state.sidebarCollapsed);
  const toggleSidebar = useUiStore((state) => state.toggleSidebar);

  return (
    <aside
      data-collapsed={collapsed}
      className={cn(
        "bg-sidebar border-sidebar-border sticky top-0 hidden h-dvh shrink-0 flex-col border-e transition-[width] duration-200 ease-out md:flex",
        collapsed ? "w-[68px]" : "w-60",
      )}
    >
      {/* Le logo porte déjà le nom : lui adjoindre un libellé textuel
          l'afficherait deux fois.

          Repliée, la colonne n'affiche que l'EMBLÈME : le logo est une bande de
          ratio 3,6 : 1, qui tomberait à 14 px de haut dans 52 px de large. Un
          carré y reste lisible, et c'est le repère de la colonne.

          Les largeurs sont bornées par la colonne, pas choisies librement :
          déployée elle mesure 240 px moins 2 × 16 px de gouttière, soit 208 px
          utiles — l'emblème (32) et sa gouttière (8) en laissent 168 au logo,
          d'où les 152 retenus : le maximum qui garde une marge visible avant la
          bordure, pour 42 px de haut dans une barre qui en fait 56. */}
      <div
        className={cn(
          "flex h-14 items-center gap-2",
          collapsed ? "justify-center px-2" : "px-4",
        )}
      >
        <Link
          href="/dashboard"
          aria-label={BRAND.name}
          className="flex min-w-0 items-center gap-2"
        >
          <BrandIcon size={collapsed ? 36 : 32} />
          {!collapsed && <BrandMark width={152} />}
        </Link>
      </div>

      <nav className="flex-1 overflow-y-auto px-3 py-2">
        {NAVIGATION.map((group) => (
          <div key={group.titleKey} className="mb-5 last:mb-0">
            {!collapsed && (
              <p className="text-muted-foreground mb-1.5 px-2 text-[0.6875rem] font-medium tracking-wider uppercase">
                {t(group.titleKey)}
              </p>
            )}
            <ul className="space-y-0.5">
              {group.items.map((item) => (
                <NavEntry key={item.href} item={item} collapsed={collapsed} />
              ))}
            </ul>
          </div>
        ))}
      </nav>

      <div className="border-sidebar-border border-t p-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={toggleSidebar}
          aria-label={t(collapsed ? "nav.expand" : "nav.collapse")}
          className={cn(
            "text-muted-foreground w-full",
            collapsed ? "justify-center px-0" : "justify-start",
          )}
        >
          <ChevronsLeft
            className={cn(
              "transition-transform duration-200",
              // rtl:rotate-180 remet la flèche dans le bon sens en arabe,
              // où « replier » pointe vers la droite.
              collapsed ? "rotate-180 rtl:rotate-0" : "rtl:rotate-180",
            )}
            aria-hidden
          />
          {!collapsed && t("nav.collapse")}
        </Button>
      </div>
    </aside>
  );
}
