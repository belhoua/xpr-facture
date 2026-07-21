import {
  adminNoteListSchema,
  adminNoteSchema,
  type AdminNote,
  type CreateNoteValues,
} from "@/features/admin-notes/schemas/note";
import { api, ensureCsrfCookie } from "@/lib/api/client";

/** ⚠️ Endpoints de Phase 3, pas encore exposés par Laravel. */
export const noteKeys = {
  all: ["admin-notes"] as const,
  list: () => [...noteKeys.all, "list"] as const,
};

export async function fetchAdminNotes(): Promise<readonly AdminNote[]> {
  const { data } = await api.get("/admin-notes");

  return adminNoteListSchema.parse(data).data;
}

export async function createAdminNote(
  values: CreateNoteValues,
): Promise<AdminNote> {
  await ensureCsrfCookie();

  const { data } = await api.post("/admin-notes", values);

  return adminNoteSchema.parse(data);
}
