"use client";

import { Moon, Sun } from "lucide-react";
import { useTranslations } from "next-intl";
import { useTheme } from "next-themes";
import { useEffect } from "react";

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
 * Monté une seule fois dans le layout applicatif ; son ouverture vit dans le
 * store Zustand pour que n'importe quel bouton puisse la déclencher.
 */
export function CommandPalette() {
  const t = useTranslations();
  const router = useRouter();
  const { theme, setTheme } = useTheme();

  const open = useUiStore((state) => state.commandPaletteOpen);
  const setOpen = useUiStore((state) => state.setCommandPaletteOpen);

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      // metaKey = ⌘ sur macOS, ctrlKey pour Windows/Linux.
      if (event.key.toLowerCase() === "k" && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        setOpen(!useUiStore.getState().commandPaletteOpen);
      }
    }

    document.addEventListener("keydown", onKeyDown);

    return () => document.removeEventListener("keydown", onKeyDown);
  }, [setOpen]);

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
