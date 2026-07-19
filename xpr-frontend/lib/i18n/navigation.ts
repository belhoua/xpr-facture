import { createNavigation } from "next-intl/navigation";

import { routing } from "./routing";

/**
 * Remplaçants de next/link et next/navigation qui conservent la locale
 * courante dans l'URL. Toujours importer d'ICI, jamais de next/*.
 */
export const { Link, redirect, usePathname, useRouter, getPathname } =
  createNavigation(routing);
