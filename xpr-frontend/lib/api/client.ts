import axios, { AxiosError, type AxiosInstance } from "axios";

/**
 * Clients HTTP uniques de l'application — personne n'appelle axios ailleurs.
 * - `withCredentials` : l'auth Sanctum fonctionne par cookies de session.
 * - `withXSRFToken` : axios renvoie le cookie XSRF-TOKEN posé par Sanctum.
 * - Accept-Language : le backend localise ses messages (SetLocale, testé).
 */
const backendUrl =
  process.env.NEXT_PUBLIC_BACKEND_URL ?? "http://localhost:8080";

function configure(instance: AxiosInstance): AxiosInstance {
  instance.interceptors.request.use((config) => {
    if (typeof document !== "undefined") {
      config.headers["Accept-Language"] = document.documentElement.lang || "fr";
    }
    return config;
  });

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
