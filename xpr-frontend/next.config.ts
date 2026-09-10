import type { NextConfig } from "next";
import createNextIntlPlugin from "next-intl/plugin";

// Branche le chargeur de messages i18n (lib/i18n/request.ts) dans le build
const withNextIntl = createNextIntlPlugin("./lib/i18n/request.ts");

// Origine du backend Laravel, résolue CÔTÉ SERVEUR Next (pas NEXT_PUBLIC_ :
// le navigateur ne doit jamais la voir). Sert de cible au reverse-proxy.
const backendOrigin = process.env.BACKEND_ORIGIN ?? "http://localhost:8080";

const nextConfig: NextConfig = {
  /**
   * Build autoportant (server.js + node_modules strictement nécessaires) :
   * c'est ce qui est transféré sur le VPS et lancé par PM2
   * (xpr-frontend/ecosystem.config.js), sans `npm install` ni `next build`
   * sur place — la boîte n'a qu'1 vCPU/4 Go, un build sur place concurrent au
   * reste de la stack y ferait facilement de l'OOM.
   *
   * Sur ce déploiement précis, Nginx-hôte route /api/* et /sanctum/*
   * directement vers le conteneur Laravel (cf. xpr-infrastructure/nginx/bcat.conf)
   * AVANT que la requête n'atteigne Next : le rewrite ci-dessous ne s'exécute
   * donc jamais pour ces deux préfixes en production. Il reste utile tel quel
   * pour le dev local et la démo Ngrok (commentaire plus bas), où c'est Next
   * qui tient le rôle de proxy.
   */
  output: "standalone",

  /**
   * Masque la pastille « N » que Next affiche en bas de page en développement.
   *
   * Ce n'est PAS un composant du layout : Next l'injecte lui-même sous
   * `next dev`, et elle n'a jamais existé en production. On la désactive parce
   * qu'elle se superpose au bouton « Replier le menu » de la sidebar, donc
   * masque une commande réelle de l'application pendant qu'on la développe.
   */
  devIndicators: false,

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
