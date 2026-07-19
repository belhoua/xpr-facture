import { redirect } from "@/lib/i18n/navigation";

/** Racine : pas d'accueil public en Phase 0, on va directement à la connexion. */
export default async function HomePage({
  params,
}: {
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  redirect({ href: "/login", locale });
}
