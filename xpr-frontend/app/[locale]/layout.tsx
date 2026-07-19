import type { Metadata } from "next";
import { hasLocale, NextIntlClientProvider } from "next-intl";
import { getTranslations } from "next-intl/server";
import { Geist_Mono, IBM_Plex_Sans_Arabic, Inter } from "next/font/google";
import { notFound } from "next/navigation";

import { Providers } from "@/app/providers";
import { isRtl, routing } from "@/lib/i18n/routing";

import "../globals.css";

// Typographie imposée (CLAUDE.md §11) : Inter pour le latin,
// IBM Plex Sans Arabic pour l'arabe (bascule via --font-sans, cf. globals.css)
const inter = Inter({ subsets: ["latin"], variable: "--font-inter" });

const plexArabic = IBM_Plex_Sans_Arabic({
  subsets: ["arabic"],
  weight: ["400", "500", "600", "700"],
  variable: "--font-plex-arabic",
});

const geistMono = Geist_Mono({
  subsets: ["latin"],
  variable: "--font-geist-mono",
});

export function generateStaticParams() {
  return routing.locales.map((locale) => ({ locale }));
}

export async function generateMetadata(): Promise<Metadata> {
  const t = await getTranslations("app");

  return { title: { default: t("name"), template: `%s — ${t("name")}` } };
}

export default async function LocaleLayout({
  children,
  params,
}: {
  children: React.ReactNode;
  params: Promise<{ locale: string }>;
}) {
  const { locale } = await params;

  if (!hasLocale(routing.locales, locale)) {
    notFound();
  }

  return (
    // dir= : TOUT l'arbre passe en RTL pour l'arabe — les propriétés
    // logiques de Tailwind v4 (ms-*, me-*, text-start…) suivent seules.
    <html lang={locale} dir={isRtl(locale) ? "rtl" : "ltr"}>
      <body
        className={`${inter.variable} ${plexArabic.variable} ${geistMono.variable} font-sans antialiased`}
      >
        <NextIntlClientProvider>
          <Providers>{children}</Providers>
        </NextIntlClientProvider>
      </body>
    </html>
  );
}
