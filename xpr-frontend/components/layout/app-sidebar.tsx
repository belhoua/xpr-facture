"use client";

import { ChevronsLeft, Sparkles } from "lucide-react";
import { useTranslations } from "next-intl";

import { NAVIGATION } from "@/components/layout/navigation";
import { Button } from "@/components/ui/button";
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
export function AppSidebar() {
  const t = useTranslations();
  const pathname = usePathname();
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
      <div className="flex h-14 items-center gap-2.5 px-4">
        <div className="bg-primary text-primary-foreground flex size-7 shrink-0 items-center justify-center rounded-md">
          <Sparkles className="size-4" aria-hidden />
        </div>
        {!collapsed && (
          <span className="font-heading truncate text-sm font-semibold tracking-tight">
            {t("app.name")}
          </span>
        )}
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
              {group.items.map((item) => {
                const active =
                  pathname === item.href || pathname.startsWith(`${item.href}/`);

                return (
                  <li key={item.href}>
                    <Link
                      href={item.href}
                      aria-current={active ? "page" : undefined}
                      title={collapsed ? t(item.titleKey) : undefined}
                      className={cn(
                        "group relative flex h-8 items-center gap-2.5 rounded-md px-2 text-sm font-medium transition-colors",
                        "focus-visible:ring-sidebar-ring focus-visible:ring-2 focus-visible:outline-none",
                        collapsed && "justify-center px-0",
                        active
                          ? "bg-sidebar-accent text-sidebar-accent-foreground"
                          : "text-muted-foreground hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground",
                      )}
                    >
                      {/* Le marqueur d'état actif est un liseré en DÉBUT de
                          ligne — il bascule seul à droite en arabe. */}
                      {active && !collapsed && (
                        <span
                          aria-hidden
                          className="bg-sidebar-primary absolute inset-y-1.5 start-0 w-0.5 rounded-full"
                        />
                      )}
                      <item.icon
                        className={cn(
                          "size-4 shrink-0",
                          active && "text-sidebar-primary",
                        )}
                        aria-hidden
                      />
                      {!collapsed && (
                        <span className="truncate">{t(item.titleKey)}</span>
                      )}
                    </Link>
                  </li>
                );
              })}
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
