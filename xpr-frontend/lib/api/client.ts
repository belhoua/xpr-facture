import axios, { AxiosError } from "axios";

/**
 * Client HTTP unique de l'application.
 * - `withCredentials` : l'auth Sanctum fonctionne par cookies de session
 *   (décision de cadrage Q5) — le navigateur doit les envoyer.
 * - `X-Requested-With` : indique à Laravel qu'il s'agit d'une requête AJAX
 *   (réponses 401 JSON au lieu d'une redirection HTML).
 * Toutes les fonctions d'API des features (`features/x/api/`) importent cette
 * instance ; personne n'appelle axios directement.
 */
export const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8080/api/v1",
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  },
});

/**
 * Forme d'erreur unifiée renvoyée par le backend (RFC 9457 Problem Details,
 * gérée côté Laravel par ProblemDetailsHandler — P0-14).
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

  return { title: "Erreur réseau", status: 0 };
}

function isApiProblem(data: unknown): data is ApiProblem {
  return (
    typeof data === "object" &&
    data !== null &&
    "title" in data &&
    "status" in data
  );
}
