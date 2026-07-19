import { create } from "zustand";

/**
 * État UI global (et UNIQUEMENT UI — l'état serveur vit dans TanStack Query,
 * CLAUDE.md §6). Consommé par le layout applicatif et la command palette ⌘K.
 */
interface UiState {
  sidebarCollapsed: boolean;
  commandPaletteOpen: boolean;
  toggleSidebar: () => void;
  setCommandPaletteOpen: (open: boolean) => void;
}

export const useUiStore = create<UiState>((set) => ({
  sidebarCollapsed: false,
  commandPaletteOpen: false,
  toggleSidebar: () =>
    set((state) => ({ sidebarCollapsed: !state.sidebarCollapsed })),
  setCommandPaletteOpen: (open) => set({ commandPaletteOpen: open }),
}));
