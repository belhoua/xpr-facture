import {
  companyUserListSchema,
  companyUserSchema,
  type CompanyUser,
  type InviteUserValues,
} from "@/features/users/schemas/user";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/** ⚠️ Endpoints livrés avec P0-10 (RBAC Spatie), pas encore exposés. */
export const userKeys = {
  all: ["users"] as const,
  list: () => [...userKeys.all, "list"] as const,
};

export async function fetchCompanyUsers(): Promise<readonly CompanyUser[]> {
  const { data } = await api.get("/users");

  return companyUserListSchema.parse(data).data;
}

export async function inviteUser(
  values: InviteUserValues,
): Promise<CompanyUser> {
  // Mutation ⇒ jeton CSRF obligatoire (auth Sanctum par cookies de session).
  await ensureCsrfCookie();

  const { data } = await api.post("/users/invitations", values);

  return companyUserSchema.parse(data);
}
