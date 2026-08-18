import axios, { AxiosError, type AxiosInstance } from "axios";

/**
 * Clients HTTP uniques de l'application — personne n'appelle axios ailleurs.
 * - `withCredentials` : l'auth Sanctum fonctionne par cookies de session.
 * - `withXSRFToken` : axios renvoie le cookie XSRF-TOKEN posé par Sanctum.
 * - Accept-Language : le backend localise ses messages (SetLocale, testé).
 */
// Par défaut, chemins RELATIFS ("" → /api/v1, /sanctum/...) : les requêtes
// partent vers l'origine qui sert le front et sont relayées au backend par le
// reverse-proxy Next (cf. next.config.ts). Même origine → pas de CORS, et le
// front reste joignable derrière Ngrok. On garde l'override absolu au cas où.
const backendUrl = process.env.NEXT_PUBLIC_BACKEND_URL ?? "";

/**
 * Chemins d'authentification publics : un 401 y est une RÉPONSE, pas une
 * session expirée. Rediriger sur « identifiants invalides » remplacerait le
 * message d'erreur du formulaire par un rechargement de la page de connexion,
 * et l'utilisateur ne saurait jamais pourquoi sa tentative a échoué.
 */
const PUBLIC_AUTH_PATHS = [
  "/auth/login",
  "/auth/register",
  "/auth/forgot-password",
  "/auth/reset-password",
];

function isPublicAuthCall(url: string | undefined): boolean {
  return url !== undefined && PUBLIC_AUTH_PATHS.some((path) => url.startsWith(path));
}

/** Locale courante lue dans l'URL (/fr/..., /ar/...), défaut fr. */
function currentLocale(): string {
  const segment = window.location.pathname.split("/")[1];

  return segment === "ar" || segment === "en" ? segment : "fr";
}

/**
 * Une redirection est déjà lancée : `window.location.assign` n'interrompt pas
 * le JavaScript en cours, et une page qui a monté six requêtes en parallèle
 * reçoit six 401. Sans ce verrou, chacune déclencherait sa propre navigation.
 */
let redirecting = false;

/**
 * Session invalide (401) : on quitte l'espace applicatif pour la page de
 * connexion, au lieu de laisser chaque écran afficher « Une erreur est
 * survenue ». C'était le symptôme d'une session absente ou expirée : toutes
 * les listes échouaient en même temps, et rien à l'écran ne disait qu'il
 * suffisait de se reconnecter.
 *
 * On ne traite QUE le 401. Un 403 signifie « connecté, mais ce rôle n'a pas ce
 * droit » : déconnecter sur 403 éjecterait un comptable qui effleure une action
 * réservée au propriétaire, et le renverrait vers un login qu'il repasserait
 * sans rien changer — une boucle dont il ne peut pas sortir. Le 403 remonte
 * donc à l'écran, qui affiche le `detail` du Problem Details.
 *
 * Rechargement complet plutôt que routeur Next : il vide le cache TanStack
 * Query avec le reste de la mémoire, ce qui garantit qu'aucune donnée du compte
 * sortant ne peut s'afficher au suivant.
 */
function handleUnauthenticated(error: AxiosError): void {
  if (typeof window === "undefined" || redirecting) {
    return;
  }

  if (isPublicAuthCall(error.config?.url)) {
    return;
  }

  const locale = currentLocale();
  const loginPath = `/${locale}/login`;

  // Déjà sur une page d'authentification : rediriger relancerait la même page
  // en boucle tant que la requête de fond échoue.
  if (window.location.pathname.startsWith(loginPath)) {
    return;
  }

  redirecting = true;
  window.location.assign(loginPath);
}

function configure(instance: AxiosInstance): AxiosInstance {
  instance.interceptors.request.use((config) => {
    if (typeof document !== "undefined") {
      config.headers["Accept-Language"] = document.documentElement.lang || "fr";
    }
    return config;
  });

  instance.interceptors.response.use(
    (response) => response,
    (error: unknown) => {
      if (error instanceof AxiosError && error.response?.status === 401) {
        handleUnauthenticated(error);
      }

      // L'erreur continue de se propager : les écrans et les formulaires
      // gardent la main sur leur affichage, et `toApiProblem` reste le seul
      // point de normalisation.
      return Promise.reject(error);
    },
  );

  return instance;
}

/** Racine du backend : uniquement /sanctum/csrf-cookie. */
const backend = configure(
  axios.create({
    baseURL: backendUrl,
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
  }),
);

/** API métier versionnée — toutes les features consomment celle-ci. */
export const api = configure(
  axios.create({
    baseURL: `${backendUrl}/api/v1`,
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
  }),
);

/**
 * À appeler avant toute mutation d'auth (login, register…) : Sanctum pose le
 * cookie XSRF que les requêtes suivantes renvoient en header.
 */
export async function ensureCsrfCookie(): Promise<void> {
  await backend.get("/sanctum/csrf-cookie");
}

/**
 * Forme d'erreur unifiée du backend (RFC 9457, ProblemDetailsRenderer).
 */
export interface ApiProblem {
  title: string;
  status: number;
  detail?: string;
  /** Erreurs de validation Laravel : champ → messages localisés */
  errors?: Record<string, string[]>;
}

export function toApiProblem(error: unknown): ApiProblem {
  if (error instanceof AxiosError && error.response) {
    const data: unknown = error.response.data;

    if (isApiProblem(data)) {
      return data;
    }

    return {
      title: error.response.statusText,
      status: error.response.status,
    };
  }

  return { title: "Network error", status: 0 };
}

function isApiProblem(data: unknown): data is ApiProblem {
  return (
    typeof data === "object" &&
    data !== null &&
    "title" in data &&
    "status" in data
  );
}
