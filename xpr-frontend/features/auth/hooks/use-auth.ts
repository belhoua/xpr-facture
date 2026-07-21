"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import type { FieldValues, Path, UseFormSetError } from "react-hook-form";

import { toApiProblem, type ApiProblem } from "@/lib/api/client";

import * as authApi from "../api/auth";
import { authKeys } from "../api/auth";

export function useLogin() {
  return useMutation({ mutationFn: authApi.login });
}

/**
 * Déconnexion : détruit la session Sanctum côté serveur puis PURGE le cache
 * TanStack Query. Sans ce `clear()`, les données de l'utilisateur sortant
 * (dont /auth/me) resteraient en mémoire et pourraient s'afficher au compte
 * suivant. La redirection est laissée à l'appelant (il connaît le routeur
 * localisé). On vide le cache même si la requête réseau échoue : l'intention
 * de l'utilisateur est de partir.
 */
export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: authApi.logout,
    onSettled: () => queryClient.clear(),
  });
}

export function useRegister() {
  return useMutation({ mutationFn: authApi.register });
}

export function useForgotPassword() {
  return useMutation({ mutationFn: authApi.forgotPassword });
}

export function useMe() {
  return useQuery({ queryKey: authKeys.me, queryFn: authApi.fetchMe });
}

/**
 * Répartit une erreur RFC 9457 du backend sur le formulaire : les erreurs de
 * validation retombent sur leurs champs (messages déjà localisés par le
 * backend), tout le reste devient une erreur globale `root.server`.
 */
export function applyProblemToForm<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: ReadonlyArray<Path<T>>,
): ApiProblem {
  const problem = toApiProblem(error);
  let hasFieldError = false;

  for (const [key, messages] of Object.entries(problem.errors ?? {})) {
    const message = messages[0];

    if ((fields as readonly string[]).includes(key) && message !== undefined) {
      setError(key as Path<T>, { type: "server", message });
      hasFieldError = true;
    }
  }

  if (!hasFieldError) {
    setError("root.server", {
      type: "server",
      message: problem.detail ?? problem.title,
    });
  }

  return problem;
}
