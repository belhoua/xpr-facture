// PM2 — sert le build `standalone` de Next.js sur le VPS.
//
// Suppose que ce fichier est déployé À LA RACINE du dossier qui contient
// `server.js` (c'est-à-dire le contenu de .next/standalone, augmenté de
// .next/static et public — cf. la méthode de transfert du build, documentée
// à côté de deploy.sh). `deploy.sh` copie ce fichier à cet endroit à chaque
// déploiement ; il n'est jamais édité sur le VPS.
//
// instances: 1, exec_mode: 'fork' — le VPS n'a qu'1 vCPU : le mode cluster de
// PM2 (plusieurs process Node) n'apporterait aucun parallélisme réel et
// doublerait juste la mémoire consommée.
module.exports = {
  apps: [
    {
      name: "bcat-frontend",
      script: "./server.js",
      cwd: __dirname,
      instances: 1,
      exec_mode: "fork",
      env: {
        NODE_ENV: "production",
        PORT: "3000",
        // 127.0.0.1 : seul Nginx-hôte doit joindre ce process (cf.
        // xpr-infrastructure/nginx/bcat.conf) — jamais exposé directement.
        HOSTNAME: "127.0.0.1",
      },
      // Coupe et redémarre le process s'il dépasse 400 Mo RSS — anti-fuite
      // mémoire, sur une boîte où aucune marge n'est disponible pour un
      // process qui dérive.
      max_memory_restart: "400M",
      autorestart: true,
      restart_delay: 2000,
      max_restarts: 10,
      time: true,
    },
  ],
};
