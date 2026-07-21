import { AppSidebar } from "@/components/layout/app-sidebar";
import { AppTopbar } from "@/components/layout/app-topbar";
import { CommandPalette } from "@/components/layout/command-palette";

/**
 * Coquille de l'espace client (P0-16). Toutes les routes de `(app)` héritent
 * de la sidebar, de la topbar et de la palette ⌘K — aucune page ne les remonte
 * elle-même.
 *
 * `min-w-0` sur la colonne de contenu est indispensable : sans lui, un tableau
 * large pousse le conteneur flex et fait défiler la PAGE horizontalement au
 * lieu du tableau.
 */
export default function AppLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="bg-background flex min-h-dvh">
      <AppSidebar />
      <div className="flex min-w-0 flex-1 flex-col">
        <AppTopbar />
        <main className="mx-auto w-full max-w-[1400px] flex-1 px-4 py-6 sm:px-6">
          {children}
        </main>
      </div>
      <CommandPalette />
    </div>
  );
}
