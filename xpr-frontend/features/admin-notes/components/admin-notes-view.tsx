"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { MessageSquareText, Send } from "lucide-react";
import { useLocale, useTranslations } from "next-intl";
import { Controller, useForm } from "react-hook-form";

import { EmptyState } from "@/components/patterns/empty-state";
import { ErrorState } from "@/components/patterns/error-state";
import { PageHeader } from "@/components/patterns/page-header";
import { Button } from "@/components/ui/button";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { Textarea } from "@/components/ui/textarea";
import {
  createAdminNote,
  fetchAdminNotes,
  noteKeys,
} from "@/features/admin-notes/api/notes";
import {
  createNoteSchema,
  NOTE_PRIORITIES,
  type CreateNoteValues,
  type NotePriority,
} from "@/features/admin-notes/schemas/note";
import { toApiProblem } from "@/lib/api/client";
import { formatDate } from "@/lib/format";
import { cn } from "@/lib/utils";

const PRIORITY_COLOR: Record<NotePriority, string> = {
  low: "text-muted-foreground",
  normal: "text-status-sent",
  high: "text-status-overdue",
};

/**
 * Espace de contact avec les administrateurs de la plateforme : composeur à
 * gauche, historique à droite.
 *
 * Les deux colonnes cohabitent volontairement sur un même écran — écrire une
 * note en voyant ses échanges précédents évite les doublons, ce qu'un
 * formulaire isolé ne permet pas.
 */
export function AdminNotesView() {
  const t = useTranslations("adminNotes");
  const tRoot = useTranslations();
  const locale = useLocale();
  const queryClient = useQueryClient();

  const notes = useQuery({
    queryKey: noteKeys.list(),
    queryFn: fetchAdminNotes,
  });

  const form = useForm<CreateNoteValues>({
    resolver: zodResolver(createNoteSchema),
    defaultValues: { subject: "", body: "", priority: "normal" },
  });

  const mutation = useMutation({
    mutationFn: createAdminNote,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: noteKeys.all });
      form.reset();
    },
  });

  const errors = form.formState.errors;
  const fieldError = (message?: string): string | undefined =>
    message?.startsWith("validation.") ? tRoot(message) : message;

  return (
    <>
      <PageHeader title={t("title")} description={t("description")} />

      <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
        <section className="bg-card ring-border rounded-lg p-5 ring-1">
          <h2 className="font-heading text-sm font-medium">
            {t("compose.title")}
          </h2>

          <form
            className="mt-4"
            onSubmit={form.handleSubmit((values) => mutation.mutate(values))}
          >
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="note-subject">
                  {t("compose.subject")}
                </FieldLabel>
                <Input
                  id="note-subject"
                  placeholder={t("compose.subjectPlaceholder")}
                  aria-invalid={Boolean(errors.subject)}
                  {...form.register("subject")}
                />
                <FieldError>{fieldError(errors.subject?.message)}</FieldError>
              </Field>

              <Field>
                <FieldLabel htmlFor="note-priority">
                  {t("compose.priority")}
                </FieldLabel>
                <Controller
                  control={form.control}
                  name="priority"
                  render={({ field }) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger id="note-priority">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        {NOTE_PRIORITIES.map((priority) => (
                          <SelectItem key={priority} value={priority}>
                            {t(`priorities.${priority}`)}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                />
              </Field>

              <Field>
                <FieldLabel htmlFor="note-body">{t("compose.body")}</FieldLabel>
                <Textarea
                  id="note-body"
                  rows={7}
                  placeholder={t("compose.bodyPlaceholder")}
                  aria-invalid={Boolean(errors.body)}
                  {...form.register("body")}
                />
                <FieldError>{fieldError(errors.body?.message)}</FieldError>
              </Field>
            </FieldGroup>

            <Button
              type="submit"
              className="mt-4 w-full"
              loading={mutation.isPending}
            >
              <Send aria-hidden />
              {mutation.isPending
                ? t("compose.submitting")
                : t("compose.submit")}
            </Button>

            {mutation.isError ? (
              <p className="text-destructive mt-2 text-sm">
                {toApiProblem(mutation.error).detail ?? tRoot("common.error")}
              </p>
            ) : null}
          </form>
        </section>

        <section className="bg-card ring-border rounded-lg ring-1">
          <div className="border-border border-b px-5 py-4">
            <h2 className="font-heading text-sm font-medium">
              {t("history.title")}
            </h2>
            <p className="text-muted-foreground mt-0.5 text-xs">
              {t("history.description")}
            </p>
          </div>

          {notes.isPending ? (
            <div className="space-y-3 p-5">
              {Array.from({ length: 3 }, (_, index) => (
                <Skeleton key={index} className="h-16 rounded-md" />
              ))}
            </div>
          ) : notes.isError ? (
            <ErrorState
              detail={toApiProblem(notes.error).detail}
              onRetry={() => void notes.refetch()}
            />
          ) : notes.data.length === 0 ? (
            <EmptyState
              icon={MessageSquareText}
              title={t("empty.title")}
              description={t("empty.description")}
            />
          ) : (
            <ul className="divide-border divide-y">
              {notes.data.map((note) => (
                <li key={note.id} className="px-5 py-3.5">
                  <div className="flex items-baseline justify-between gap-3">
                    <p className="truncate text-sm font-medium">
                      {note.subject}
                    </p>
                    <span className="text-muted-foreground shrink-0 text-xs">
                      {formatDate(note.createdAt, locale)}
                    </span>
                  </div>
                  <p className="text-muted-foreground mt-1 line-clamp-2 text-sm">
                    {note.body}
                  </p>
                  <span
                    className={cn(
                      "mt-1.5 inline-block text-xs font-medium",
                      PRIORITY_COLOR[note.priority],
                    )}
                  >
                    {t(`priorities.${note.priority}`)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </section>
      </div>
    </>
  );
}
