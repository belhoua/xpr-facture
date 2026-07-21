import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

// Branche le chargeur de messages i18n (lib/i18n/request.ts) dans le build
const withNextIntl = createNextIntlPlugin("./lib/i18n/request.ts");

// Origine du backend Laravel, résolue CÔTÉ SERVEUR Next (pas NEXT_PUBLIC_ :
// le navigateur ne doit jamais la voir). Sert de cible au reverse-proxy.
const backendOrigin = process.env.BACKEND_ORIGIN ?? "http://localhost:8080";

const nextConfig: NextConfig = {
  /**
   * Reverse-proxy : le navigateur n'appelle QUE l'origine qui sert le front
   * (localhost:3000 en local, l'URL Ngrok en démo). Next relaie ces requêtes
   * vers le backend depuis le serveur. Conséquence : même origine pour tout →
   * plus de CORS ni de « Network Error » quand le front est exposé via Ngrok.
   *
   * On proxifie les deux préfixes que le client consomme :
   *  - /api/*     → API métier versionnée (/api/v1/...)
   *  - /sanctum/* → cookie CSRF de Sanctum (/sanctum/csrf-cookie)
   */
  async rewrites() {
    return [
      { source: "/api/:path*", destination: `${backendOrigin}/api/:path*` },
      {
        source: "/sanctum/:path*",
        destination: `${backendOrigin}/sanctum/:path*`,
      },
    ];
  },
};

export default withNextIntl(nextConfig);
