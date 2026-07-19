"use client";

import { useMutation, useQuery } from "@tanstack/react-query";
import type { FieldValues, Path, UseFormSetError } from "react-hook-form";

import { toApiProblem, type ApiProblem } from "@/lib/api/client";

import * as authApi from "../api/auth";
import { authKeys } from "../api/auth";

export function useLogin() {
  return useMutation({ mutationFn: authApi.login });
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
