"use client";

import { LogOut, Menu, Search, Settings } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";

import { BrandIcon } from "@/components/layout/brand-icon";
import { BrandMark } from "@/components/layout/brand-mark";
import { Calculator } from "@/components/layout/calculator";
import { LocaleSwitcher } from "@/components/layout/locale-switcher";
import { NAVIGATION } from "@/components/layout/navigation";
import { ThemeToggle } from "@/components/layout/theme-toggle";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet";
import { useLogout } from "@/features/auth/hooks/use-auth";
import { OverdueInvoicesMenu } from "@/features/documents/components/overdue-invoices-menu";
import { BRAND } from "@/lib/brand";
import { Link, usePathname, useRouter } from "@/lib/i18n/navigation";
import { isRtl } from "@/lib/i18n/routing";
import { cn } from "@/lib/utils";
import { useUiStore } from "@/stores/ui";

/**
 * Barre supérieure : navigation mobile, déclencheur de la palette ⌘K,
 * préférences (thème, langue) et menu utilisateur.
 *
 * Le champ de recherche n'est PAS un input : c'est un bouton qui ouvre la
 * palette. Un seul point d'entrée pour la recherche, au clavier comme à la
 * souris, plutôt que deux mécanismes concurrents.
 */
export function AppTopbar() {
  const t = useTranslations();
  const pathname = usePathname();
  const router = useRouter();
  const rtl = isRtl(useLocale());
  const logout = useLogout();
  const setCommandPaletteOpen = useUiStore(
    (state) => state.setCommandPaletteOpen,
  );

  async function handleLogout(): Promise<void> {
    // `mutateAsync` ne rejette pas ici : `useLogout` purge le cache dans tous
    // les cas, et l'utilisateur doit repartir vers la connexion même si le
    // POST /auth/logout a échoué (session déjà expirée, réseau coupé…).
    await logout.mutateAsync().catch(() => undefined);
    router.replace("/login");
  }

  return (
    <header className="bg-background/80 border-border sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-3 backdrop-blur-md sm:px-4">
      <Sheet>
        <SheetTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            className="md:hidden"
            aria-label={t("nav.openMenu")}
          >
            <Menu aria-hidden />
          </Button>
        </SheetTrigger>
        {/* Le tiroir s'ouvre du côté du DÉBUT de lecture : à gauche en français,
            à droite en arabe. `Sheet` ne connaissant que les côtés physiques,
            c'est la locale qui tranche. */}
        <SheetContent side={rtl ? "right" : "left"} className="w-64 p-0">
          <SheetHeader className="h-14 flex-row items-center gap-2.5 border-b px-4">
            {/* Tiroir en `w-64` moins 2 × 16 px de gouttière : 224 px utiles,
                dont 40 pour l'emblème et sa gouttière — 184 restent au logo,
                d'où 160. */}
            <BrandIcon size={32} />
            <BrandMark width={160} />
            {/* Le titre reste présent pour les lecteurs d'écran : le logo est
                une image, `SheetTitle` est ce qui nomme le tiroir. */}
            <SheetTitle className="sr-only">{BRAND.name}</SheetTitle>
          </SheetHeader>
          <nav className="px-3 py-3">
            {NAVIGATION.map((group) => (
              <div key={group.titleKey} className="mb-5 last:mb-0">
                <p className="text-muted-foreground mb-1.5 px-2 text-[0.6875rem] font-medium tracking-wider uppercase">
                  {t(group.titleKey)}
                </p>
                <ul className="space-y-0.5">
                  {group.items.map((item) => {
                    const active = pathname.startsWith(item.href);

                    return (
                      <li key={item.href}>
                        <Link
                          href={item.href}
                          className={cn(
                            "flex h-8 items-center gap-2.5 rounded-md px-2 text-sm font-medium",
                            active
                              ? "bg-sidebar-accent text-sidebar-accent-foreground"
                              : "text-muted-foreground hover:bg-sidebar-accent/60",
                          )}
                        >
                          <item.icon className="size-4" aria-hidden />
                          {t(item.titleKey)}
                        </Link>
                      </li>
                    );
                  })}
                </ul>
              </div>
            ))}
          </nav>
        </SheetContent>
      </Sheet>

      {/* Sous md la sidebar est masquée : sans ce rappel, l'écran n'afficherait
          la marque nulle part. Au-dessus, elle serait affichée deux fois.

          Volontairement plus étroit que dans la sidebar : cette barre porte
          aussi le menu, la recherche et quatre actions, sur 375 px de large au
          pire. La marque y est un rappel, pas l'en-tête de la colonne. */}
      <Link
        href="/dashboard"
        aria-label={BRAND.name}
        className="flex items-center gap-1.5 md:hidden"
      >
        <BrandIcon size={28} />
        <BrandMark width={104} />
      </Link>

      <button
        type="button"
        onClick={() => setCommandPaletteOpen(true)}
        // `min-w-0` : sans lui un élément flex refuse de passer sous la largeur
        // de son contenu, et le libellé de recherche pousserait la barre en
        // débordement sur petit écran, où l'emblème et le logo occupent
        // désormais 138 px à eux deux.
        className="text-muted-foreground ring-border hover:bg-muted/60 hover:text-foreground focus-visible:ring-ring flex h-8 max-w-72 min-w-0 flex-1 items-center gap-2 rounded-md px-2.5 text-sm ring-1 transition-colors focus-visible:ring-2 focus-visible:outline-none"
      >
        <Search className="size-4 shrink-0" aria-hidden />
        <span className="truncate">{t("nav.searchPlaceholder")}</span>
        <kbd className="bg-muted text-muted-foreground ms-auto hidden rounded border px-1.5 py-0.5 font-mono text-[0.6875rem] sm:inline-block">
          ⌘K
        </kbd>
      </button>

      <div className="ms-auto flex items-center gap-1">
        <Calculator />
        {/* Les impayés se signalent partout, pas seulement sur l'écran des
            factures : c'est ce qu'on veut voir sans aller le chercher. */}
        <OverdueInvoicesMenu />
        <LocaleSwitcher />
        <ThemeToggle />

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              aria-label={t("nav.account")}
              className="rounded-full"
            >
              <Avatar className="size-7">
                <AvatarFallback className="bg-primary/10 text-primary text-xs font-medium">
                  XP
                </AvatarFallback>
              </Avatar>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-52">
            <DropdownMenuLabel>{t("nav.account")}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            {/* asChild : l'item devient une vraie ancre localisée — clic droit,
                Cmd+clic et préchargement du routeur fonctionnent. */}
            <DropdownMenuItem asChild>
              <Link href="/settings">
                <Settings aria-hidden />
                {t("nav.settings")}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              variant="destructive"
              disabled={logout.isPending}
              // onSelect plutôt que onClick : Radix ferme le menu proprement et
              // ne réagit qu'à une sélection clavier/souris valide.
              onSelect={(event) => {
                event.preventDefault();
                void handleLogout();
              }}
            >
              <LogOut aria-hidden />
              {t("nav.logout")}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}
