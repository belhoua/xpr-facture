import { api, ensureCsrfCookie } from "@/lib/api/client";

import type {
  ForgotPasswordValues,
  LoginValues,
  RegisterValues,
} from "../schemas/auth";
import type {
  LoginResponse,
  MeResponse,
  RegisterResponse,
} from "../types/auth";

/** Clés TanStack Query de la feature — uniques dans l'application. */
export const authKeys = {
  me: ["auth", "me"] as const,
};

export async function register(values: RegisterValues): Promise<RegisterResponse> {
  await ensureCsrfCookie();
  const { data } = await api.post<RegisterResponse>("/auth/register", values);

  return data;
}

export async function login(values: LoginValues): Promise<LoginResponse> {
  await ensureCsrfCookie();
  const { data } = await api.post<LoginResponse>("/auth/login", values);

  return data;
}

export async function logout(): Promise<void> {
  await api.post("/auth/logout");
}

export async function forgotPassword(
  values: ForgotPasswordValues,
): Promise<void> {
  await ensureCsrfCookie();
  await api.post("/auth/forgot-password", values);
}

export async function fetchMe(): Promise<MeResponse> {
  const { data } = await api.get<MeResponse>("/auth/me");

  return data;
}
