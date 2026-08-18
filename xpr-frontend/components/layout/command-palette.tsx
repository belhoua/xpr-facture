"use client";

import { Moon, Sun } from "lucide-react";
import { useTranslations } from "next-intl";
import { useTheme } from "next-themes";

import { NAVIGATION } from "@/components/layout/navigation";
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
} from "@/components/ui/command";
import { useRouter } from "@/lib/i18n/navigation";
import { useUiStore } from "@/stores/ui";

/**
 * Command palette ⌘K globale, exigée dès la Phase 0 (CLAUDE.md §11).
 * Elle lit la MÊME `NAVIGATION` que la sidebar : un écran ajouté au routeur
 * devient navigable au clavier sans une ligne de plus.
 *
 * Ce composant ne porte QUE le dialogue. Le raccourci clavier vit dans
 * `CommandPaletteHost`, qui le monte seul dans la coquille applicative et ne
 * télécharge ce fichier — et cmdk avec lui — qu'à la première ouverture. Les
 * deux ne peuvent pas être réunis : un écouteur embarqué ici n'existerait
 * qu'une fois le code chargé, c'est-à-dire jamais si l'utilisateur compte
 * précisément sur ⌘K pour l'ouvrir.
 */
export function CommandPalette() {
  const t = useTranslations();
  const router = useRouter();
  const { theme, setTheme } = useTheme();

  const open = useUiStore((state) => state.commandPaletteOpen);
  const setOpen = useUiStore((state) => state.setCommandPaletteOpen);

  function go(href: string) {
    setOpen(false);
    router.push(href);
  }

  return (
    <CommandDialog
      open={open}
      onOpenChange={setOpen}
      title={t("nav.commandPalette")}
      description={t("nav.commandPaletteHint")}
    >
      <CommandInput placeholder={t("nav.commandPalettePlaceholder")} />
      <CommandList>
        <CommandEmpty>{t("common.empty")}</CommandEmpty>

        {NAVIGATION.map((group) => (
          <CommandGroup key={group.titleKey} heading={t(group.titleKey)}>
            {group.items.map((item) => (
              <CommandItem
                key={item.href}
                value={t(item.titleKey)}
                onSelect={() => go(item.href)}
              >
                <item.icon aria-hidden />
                <span>{t(item.titleKey)}</span>
                {item.shortcut ? (
                  <CommandShortcut>⌘{item.shortcut}</CommandShortcut>
                ) : null}
              </CommandItem>
            ))}
          </CommandGroup>
        ))}

        <CommandSeparator />

        <CommandGroup heading={t("nav.groups.preferences")}>
          <CommandItem
            value={t("nav.toggleTheme")}
            onSelect={() => {
              setTheme(theme === "dark" ? "light" : "dark");
              setOpen(false);
            }}
          >
            {theme === "dark" ? <Sun aria-hidden /> : <Moon aria-hidden />}
            <span>{t("nav.toggleTheme")}</span>
          </CommandItem>
        </CommandGroup>
      </CommandList>
    </CommandDialog>
  );
}
